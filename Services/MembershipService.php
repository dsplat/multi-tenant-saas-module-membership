<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Membership\Models\MemberAccount;
use MultiTenantSaas\Modules\Membership\Models\MembershipLevel;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 会员等级服务（Console）
 *
 * 等级配置 CRUD / 升级规则评估 / 等级变更（含变更日志）/ 分布统计。
 *
 * 物理归位说明：由 scrm 自建模块提升进框架。按身份铁律「去 customer 口径」——
 * 变更日志表 customer_membership_logs 去前缀重命名为 membership_logs、主键改
 * membership_log_id；用户身份一律走框架 user_id（不再引用 Customer 实体）。
 */
class MembershipService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    // ========== 等级配置 CRUD ==========

    public function create($tenantId, array $data): MembershipLevel
    {
        TenantContext::assertTenantId($tenantId);

        return MembershipLevel::create([
            'membership_level_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'validity_days' => (int) ($data['validity_days'] ?? 0),
            'upgrade_conditions' => $data['upgrade_conditions'] ?? null,
            'retain_conditions' => $data['retain_conditions'] ?? null,
            'benefits' => $data['benefits'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);
    }

    public function getLevels($tenantId): Collection
    {
        TenantContext::assertTenantId($tenantId);

        return MembershipLevel::where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('membership_level_id')
            ->get();
    }

    public function getLevel($tenantId, $levelId): MembershipLevel
    {
        TenantContext::assertTenantId($tenantId);

        return MembershipLevel::where('membership_level_id', $levelId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
    }

    public function update($tenantId, $levelId, array $data): MembershipLevel
    {
        TenantContext::assertTenantId($tenantId);

        $level = MembershipLevel::where('membership_level_id', $levelId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $fillable = ['name', 'icon', 'sort_order', 'validity_days', 'upgrade_conditions', 'retain_conditions', 'benefits', 'status'];
        $level->update(array_intersect_key($data, array_flip($fillable)));

        return $level->fresh();
    }

    public function delete($tenantId, $levelId): bool
    {
        TenantContext::assertTenantId($tenantId);

        $level = MembershipLevel::where('membership_level_id', $levelId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return (bool) $level->delete();
    }

    // ========== 升级规则评估 ==========

    /**
     * 评估用户是否满足升级条件
     */
    public function evaluateUpgrade($tenantId, $userId): ?MembershipLevel
    {
        TenantContext::assertTenantId($tenantId);

        $levels = MembershipLevel::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderByDesc('sort_order')
            ->orderByDesc('membership_level_id')
            ->get();

        // 获取用户统计数据
        $userStats = $this->getUserStats($tenantId, $userId);

        foreach ($levels as $level) {
            $conditions = $level->upgrade_conditions ?? [];
            if (empty($conditions)) {
                continue;
            }

            if ($this->matchConditions($userStats, $conditions)) {
                return $level;
            }
        }

        return null;
    }

    /**
     * 执行等级变更
     */
    public function changeLevel($tenantId, $userId, $toLevelId, string $changeType = 'upgrade', ?string $reason = null): array
    {
        TenantContext::assertTenantId($tenantId);

        $toLevel = MembershipLevel::where('membership_level_id', $toLevelId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return DB::transaction(function () use ($tenantId, $userId, $toLevel, $toLevelId, $changeType, $reason) {
            $now = now();
            $levelExpiresAt = (int) $toLevel->validity_days > 0
                ? $now->copy()->addDays((int) $toLevel->validity_days)
                : null;

            $currentLog = DB::table('membership_logs')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->orderByDesc('created_at')
                ->orderByDesc('membership_log_id')
                ->first();

            $fromLevelId = $currentLog ? (int) $currentLog->to_level_id : null;

            $logId = $this->idGenerator->generate();
            DB::table('membership_logs')->insert([
                'membership_log_id' => $logId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'from_level_id' => $fromLevelId,
                'to_level_id' => $toLevelId,
                'change_type' => $changeType,
                'reason' => $reason,
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);

            // 账户投影与等级日志同事务落库：折扣、成就和 C 端档案读取
            // current_level_id 时不会看到「日志已升级、账户仍是旧等级」的中间态。
            if (Schema::hasTable('member_accounts')) {
                $account = DB::table('member_accounts')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();

                if ($account) {
                    DB::table('member_accounts')
                        ->where('member_account_id', $account->member_account_id)
                        ->update([
                            'current_level_id' => $toLevelId,
                            'level_status' => 'active',
                            'level_started_at' => $now->toDateTimeString(),
                            'level_expires_at' => $levelExpiresAt?->toDateTimeString(),
                            'updated_at' => $now->toDateTimeString(),
                        ]);
                } else {
                    DB::table('member_accounts')->insert([
                        'member_account_id' => $this->idGenerator->generate(),
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'growth_value' => 0,
                        'current_level_id' => $toLevelId,
                        'level_status' => 'active',
                        'level_started_at' => $now->toDateTimeString(),
                        'level_expires_at' => $levelExpiresAt?->toDateTimeString(),
                        'created_at' => $now->toDateTimeString(),
                        'updated_at' => $now->toDateTimeString(),
                    ]);
                }
            }

            return [
                'log_id' => $logId,
                'user_id' => $userId,
                'from_level_id' => $fromLevelId,
                'to_level_id' => $toLevelId,
                'change_type' => $changeType,
            ];
        });
    }

    // ========== 权益管理 ==========

    public function updateBenefits($tenantId, $levelId, array $benefits): MembershipLevel
    {
        TenantContext::assertTenantId($tenantId);

        $level = MembershipLevel::where('membership_level_id', $levelId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
        $level->update(['benefits' => $benefits]);

        return $level->fresh();
    }

    public function getBenefits($tenantId, $levelId): array
    {
        TenantContext::assertTenantId($tenantId);

        $level = MembershipLevel::where('membership_level_id', $levelId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return [
            'level_id' => $levelId,
            'name' => $level->name,
            'benefits' => $level->benefits ?? [],
        ];
    }

    // ========== 变更日志 ==========

    public function getChangeLogs($tenantId, $userId): array
    {
        TenantContext::assertTenantId($tenantId);

        $logs = DB::table('membership_logs')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('membership_log_id')
            ->get()
            ->toArray();

        // 补充等级名称
        $levelIds = [];
        foreach ($logs as $log) {
            if ($log->from_level_id) {
                $levelIds[] = (int) $log->from_level_id;
            }
            if ($log->to_level_id) {
                $levelIds[] = (int) $log->to_level_id;
            }
        }
        $levelIds = array_unique($levelIds);

        $levels = ! empty($levelIds)
            ? MembershipLevel::whereIn('membership_level_id', $levelIds)->get()->keyBy('membership_level_id')
            : collect();

        $result = [];
        foreach ($logs as $log) {
            $result[] = [
                'log_id' => $log->membership_log_id,
                'from_level' => $log->from_level_id ? ($levels->get((int) $log->from_level_id)?->name ?? '未知') : '无',
                'to_level' => $log->to_level_id ? ($levels->get((int) $log->to_level_id)?->name ?? '未知') : '无',
                'change_type' => $log->change_type,
                'reason' => $log->reason,
                'created_at' => $log->created_at,
            ];
        }

        return $result;
    }

    // ========== 分布统计 ==========

    public function getDistribution($tenantId): array
    {
        TenantContext::assertTenantId($tenantId);

        $levels = MembershipLevel::where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('membership_level_id')
            ->get();

        // 分布按账户当前有效等级统计；已过期账户不计入会员。
        if (Schema::hasTable('member_accounts') && Schema::hasColumn('member_accounts', 'level_status')) {
            $latestLogs = DB::table('member_accounts')
                ->where('tenant_id', $tenantId)
                ->where('level_status', 'active')
                ->whereNotNull('current_level_id')
                ->where(function ($query): void {
                    $query->whereNull('level_expires_at')
                        ->orWhere('level_expires_at', '>', now()->toDateTimeString());
                })
                ->selectRaw('current_level_id as to_level_id, COUNT(DISTINCT user_id) as member_count')
                ->groupBy('current_level_id')
                ->get()
                ->keyBy('to_level_id');
        } else {
            $latestLogs = DB::table('membership_logs as cml1')
                ->where('cml1.tenant_id', $tenantId)
                ->whereNotNull('cml1.to_level_id')
                ->whereRaw('cml1.created_at = (SELECT MAX(cml2.created_at) FROM membership_logs cml2 WHERE cml2.tenant_id = cml1.tenant_id AND cml2.user_id = cml1.user_id)')
                ->selectRaw('cml1.to_level_id, COUNT(DISTINCT cml1.user_id) as member_count')
                ->groupBy('cml1.to_level_id')
                ->get()
                ->keyBy('to_level_id');
        }

        $totalMembers = 0;
        $levelStats = [];

        foreach ($levels as $level) {
            $count = isset($latestLogs[$level->membership_level_id])
                ? (int) $latestLogs[$level->membership_level_id]->member_count
                : 0;
            $totalMembers += $count;

            $levelStats[] = [
                'level_id' => $level->membership_level_id,
                'name' => $level->name,
                'count' => $count,
                'percentage' => 0,
            ];
        }

        foreach ($levelStats as &$stat) {
            $stat['percentage'] = $totalMembers > 0
                ? round(($stat['count'] / $totalMembers) * 100, 2)
                : 0;
        }

        return [
            'total_members' => $totalMembers,
            'levels' => $levelStats,
        ];
    }

    // ========== 成长与自动升级 ==========

    /**
     * 按当前成长值重算等级（只升级，不降级）。
     *
     * 事件监听器在成长入账后调用；返回 null 表示未发生等级变化。
     */
    public function reconcileLevelWithGrowth($tenantId, $userId): ?array
    {
        TenantContext::assertTenantId($tenantId);

        if (! Schema::hasTable('member_accounts')) {
            return null;
        }

        $account = MemberAccount::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();

        if (! $account) {
            return null;
        }

        // 已过期（含已到时间但定时任务尚未处理）停止自动升级；续期后才恢复。
        if ($account->level_status === 'expired'
            || ($account->level_status === 'active'
                && $account->level_expires_at
                && ! $account->level_expires_at->isFuture())) {
            return null;
        }

        $levels = MembershipLevel::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('membership_level_id')
            ->get();

        if ($levels->isEmpty()) {
            return null;
        }

        $stats = $this->getUserStats($tenantId, $userId);
        $target = null;

        foreach ($levels as $level) {
            $conditions = $level->upgrade_conditions ?? [];

            if (empty($conditions)) {
                continue;
            }

            if ($this->matchConditions($stats, $conditions)) {
                $target = $level;
            }
        }

        if (! $target) {
            return null;
        }

        $current = $account->current_level_id
            ? $levels->firstWhere('membership_level_id', (int) $account->current_level_id)
            : null;
        $currentSort = $current ? (int) $current->sort_order : -1;

        if ((int) $target->sort_order <= $currentSort) {
            return null;
        }

        return $this->changeLevel(
            $tenantId,
            $userId,
            (int) $target->membership_level_id,
            'upgrade',
            '成长值自动升级',
        );
    }

    /**
     * C 端会员档案：账户、当前等级、有效期与权益。
     */
    public function getMemberProfile($tenantId, $userId): array
    {
        TenantContext::assertTenantId($tenantId);

        $account = Schema::hasTable('member_accounts')
            ? MemberAccount::where('tenant_id', $tenantId)->where('user_id', $userId)->first()
            : null;

        $level = $account && $account->current_level_id
            ? MembershipLevel::where('tenant_id', $tenantId)
                ->where('membership_level_id', $account->current_level_id)
                ->first()
            : null;

        $isExpired = $account && (
            $account->level_status === 'expired'
            || ($account->level_status === 'active'
                && $account->level_expires_at
                && ! $account->level_expires_at->isFuture())
        );

        return [
            'user_id' => $userId,
            'growth_value' => (int) ($account->growth_value ?? 0),
            'level_status' => $isExpired ? 'expired' : ($level ? 'active' : 'none'),
            'level_started_at' => $account?->level_started_at,
            'level_expires_at' => $account?->level_expires_at,
            'validity_days' => $level ? (int) $level->validity_days : 0,
            'level' => $level && ! $isExpired ? [
                'level_id' => (int) $level->membership_level_id,
                'name' => $level->name,
                'icon' => $level->icon,
                'benefits' => $level->benefits ?? [],
            ] : null,
            'expired_level' => $isExpired && $level ? [
                'level_id' => (int) $level->membership_level_id,
                'name' => $level->name,
            ] : null,
        ];
    }

    /**
     * 逐租户会员生命周期：到期续期/过期、以及成长升级。
     *
     * @return array{renewed: int, expired: int, upgraded: int}
     */
    public function processMembershipLifecycle($tenantId): array
    {
        TenantContext::assertTenantId($tenantId);

        $summary = ['renewed' => 0, 'expired' => 0, 'upgraded' => 0];

        if (! Schema::hasTable('member_accounts')
            || ! Schema::hasColumn('member_accounts', 'level_status')) {
            return $summary;
        }

        $accounts = MemberAccount::where('tenant_id', $tenantId)
            ->whereIn('level_status', ['none', 'active'])
            ->orderBy('member_account_id')
            ->get();

        foreach ($accounts as $account) {
            $userId = (int) $account->user_id;

            if ($account->level_status === 'active'
                && $account->level_expires_at
                && ! $account->level_expires_at->isFuture()) {
                $level = $account->current_level_id
                    ? MembershipLevel::where('tenant_id', $tenantId)
                        ->where('membership_level_id', $account->current_level_id)
                        ->first()
                    : null;

                if ($level && $this->canRetain($tenantId, $userId, $level)) {
                    $this->renewMembership($tenantId, $userId, null, null, '保级条件自动续期');
                    $summary['renewed']++;
                } else {
                    $this->expireMembership($tenantId, $userId, '会员到期且未满足保级条件');
                    $summary['expired']++;

                    continue;
                }
            }

            if ($this->reconcileLevelWithGrowth($tenantId, $userId) !== null) {
                $summary['upgraded']++;
            }
        }

        return $summary;
    }

    /**
     * 运营续期：重开当前等级的新有效周期。
     */
    public function renewMembership(
        $tenantId,
        $userId,
        ?int $days = null,
        ?int $levelId = null,
        string $reason = '运营续期',
    ): array {
        TenantContext::assertTenantId($tenantId);

        return DB::transaction(function () use ($tenantId, $userId, $days, $levelId, $reason) {
            $account = MemberAccount::where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                throw new UnprocessableEntityHttpException('Member account not found');
            }

            $targetLevelId = $levelId ?? (int) $account->current_level_id;

            if ($targetLevelId <= 0) {
                throw new UnprocessableEntityHttpException('Member has no level to renew');
            }

            $level = MembershipLevel::where('tenant_id', $tenantId)
                ->where('membership_level_id', $targetLevelId)
                ->firstOrFail();

            $renewDays = $days ?? (int) $level->validity_days;

            if ($renewDays <= 0) {
                throw new UnprocessableEntityHttpException('Membership validity must be positive');
            }

            $now = now();
            $expiresAt = $now->copy()->addDays($renewDays);

            $account->update([
                'current_level_id' => $targetLevelId,
                'level_status' => 'active',
                'level_started_at' => $now,
                'level_expires_at' => $expiresAt,
            ]);

            $logId = $this->idGenerator->generate();
            DB::table('membership_logs')->insert([
                'membership_log_id' => $logId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'from_level_id' => $targetLevelId,
                'to_level_id' => $targetLevelId,
                'change_type' => 'retain',
                'reason' => $reason,
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);

            return [
                'log_id' => $logId,
                'user_id' => $userId,
                'level_id' => $targetLevelId,
                'level_started_at' => $now->toDateTimeString(),
                'level_expires_at' => $expiresAt->toDateTimeString(),
            ];
        });
    }

    /**
     * 会员到期：降为普通会员（保留 current_level_id 供续期/审计），并停止自动升级。
     */
    public function expireMembership($tenantId, $userId, string $reason = '会员到期'): bool
    {
        TenantContext::assertTenantId($tenantId);

        return DB::transaction(function () use ($tenantId, $userId, $reason) {
            $account = MemberAccount::where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $account || $account->level_status !== 'active') {
                return false;
            }

            $fromLevelId = (int) $account->current_level_id;

            $account->update(['level_status' => 'expired']);

            $logId = $this->idGenerator->generate();
            DB::table('membership_logs')->insert([
                'membership_log_id' => $logId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'from_level_id' => $fromLevelId ?: null,
                'to_level_id' => null,
                'change_type' => 'downgrade',
                'reason' => $reason,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            return true;
        });
    }

    private function canRetain($tenantId, $userId, MembershipLevel $level): bool
    {
        $conditions = $level->retain_conditions ?? [];

        if (empty($conditions)) {
            return false;
        }

        return $this->matchConditions($this->getUserStats($tenantId, $userId), $conditions);
    }

    // ========== 私有方法 ==========

    private function getUserStats($tenantId, $userId): array
    {
        $stats = [
            'total_spent' => 0,
            'total_transactions' => 0,
            'total_points' => 0,
            'days_since_join' => 0,
            'growth_value' => 0,
        ];

        // 交易统计：orders.total_amount 为实付现金（整数分），只计 paid 订单。
        // 表由可选 Order 模块提供：未安装时明确按 0 处理，不把「表不存在」伪装成真实零消费。
        if (Schema::hasTable('orders')) {
            $txStats = DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('status', 'paid')
                ->selectRaw('COUNT(*) as total_transactions, COALESCE(SUM(total_amount), 0) as total_spent')
                ->first();

            $stats['total_spent'] = (int) ($txStats->total_spent ?? 0);
            $stats['total_transactions'] = (int) ($txStats->total_transactions ?? 0);
        }

        if (Schema::hasTable('points_transactions')) {
            $lastTx = DB::table('points_transactions')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->orderByDesc('points_transaction_id')
                ->first();

            $stats['total_points'] = $lastTx ? (int) $lastTx->balance_after : 0;
        }

        if (Schema::hasTable('member_accounts')) {
            $stats['growth_value'] = (int) (DB::table('member_accounts')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->value('growth_value') ?? 0);
        }

        if (Schema::hasTable('users')) {
            $user = DB::table('users')
                ->where('user_id', $userId)
                ->first();

            if ($user && $user->created_at) {
                $stats['days_since_join'] = (int) now()->diffInDays(Carbon::parse($user->created_at));
            }
        }

        return $stats;
    }

    private function matchConditions(array $stats, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? '>=';
            $value = $condition['value'] ?? 0;
            $actual = $stats[$field] ?? 0;

            $matched = match ($operator) {
                '>=' => $actual >= $value,
                '>' => $actual > $value,
                '<=' => $actual <= $value,
                '<' => $actual < $value,
                '==' => $actual == $value,
                default => false,
            };

            if (! $matched) {
                return false;
            }
        }

        return true;
    }
}
