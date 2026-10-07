<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Membership\Models\MembershipLevel;

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
        TenantContext::setTenantId((string) $tenantId);

        return MembershipLevel::create([
            'membership_level_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'upgrade_conditions' => $data['upgrade_conditions'] ?? null,
            'retain_conditions' => $data['retain_conditions'] ?? null,
            'benefits' => $data['benefits'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);
    }

    public function getLevels($tenantId): Collection
    {
        TenantContext::setTenantId((string) $tenantId);

        return MembershipLevel::where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('membership_level_id')
            ->get();
    }

    public function getLevel($tenantId, $levelId): MembershipLevel
    {
        TenantContext::setTenantId((string) $tenantId);

        return MembershipLevel::where('membership_level_id', $levelId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
    }

    public function update($tenantId, $levelId, array $data): MembershipLevel
    {
        TenantContext::setTenantId((string) $tenantId);

        $level = MembershipLevel::where('membership_level_id', $levelId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $fillable = ['name', 'icon', 'sort_order', 'upgrade_conditions', 'retain_conditions', 'benefits', 'status'];
        $level->update(array_intersect_key($data, array_flip($fillable)));

        return $level->fresh();
    }

    public function delete($tenantId, $levelId): bool
    {
        TenantContext::setTenantId((string) $tenantId);

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
        TenantContext::setTenantId((string) $tenantId);

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
        TenantContext::setTenantId((string) $tenantId);

        $toLevel = MembershipLevel::where('membership_level_id', $toLevelId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return DB::transaction(function () use ($tenantId, $userId, $toLevelId, $changeType, $reason) {
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
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

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
        TenantContext::setTenantId((string) $tenantId);

        $level = MembershipLevel::where('membership_level_id', $levelId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
        $level->update(['benefits' => $benefits]);

        return $level->fresh();
    }

    public function getBenefits($tenantId, $levelId): array
    {
        TenantContext::setTenantId((string) $tenantId);

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
        TenantContext::setTenantId((string) $tenantId);

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
        TenantContext::setTenantId((string) $tenantId);

        $levels = MembershipLevel::where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('membership_level_id')
            ->get();

        // 取每个用户的最新等级日志
        $latestLogs = DB::table('membership_logs as cml1')
            ->where('cml1.tenant_id', $tenantId)
            ->whereNotNull('cml1.to_level_id')
            ->whereRaw('cml1.created_at = (SELECT MAX(cml2.created_at) FROM membership_logs cml2 WHERE cml2.tenant_id = cml1.tenant_id AND cml2.user_id = cml1.user_id)')
            ->selectRaw('cml1.to_level_id, COUNT(DISTINCT cml1.user_id) as member_count')
            ->groupBy('cml1.to_level_id')
            ->get()
            ->keyBy('to_level_id');

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

    // ========== 私有方法 ==========

    private function getUserStats($tenantId, $userId): array
    {
        $stats = [
            'total_spent' => 0,
            'total_transactions' => 0,
            'total_points' => 0,
            'days_since_join' => 0,
        ];

        // 交易统计（表可能不存在；金额口径以整数最小单位为准，本处仅作升级阈值比较）
        try {
            $txStats = DB::table('card_transactions')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->selectRaw('COUNT(*) as total_transactions, COALESCE(SUM(ABS(amount)), 0) as total_spent')
                ->first();

            $stats['total_spent'] = (int) ($txStats->total_spent ?? 0);
            $stats['total_transactions'] = (int) ($txStats->total_transactions ?? 0);
        } catch (\Exception $e) {
            // card_transactions 表不存在时使用默认值
        }

        // 积分统计（表可能不存在）
        try {
            $lastTx = DB::table('points_transactions')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->orderByDesc('points_transaction_id')
                ->first();

            $stats['total_points'] = $lastTx ? (int) $lastTx->balance_after : 0;
        } catch (\Exception $e) {
            // points_transactions 表不存在时使用默认值
        }

        // 用户注册天数
        try {
            $user = DB::table('users')
                ->where('user_id', $userId)
                ->first();

            if ($user && $user->created_at) {
                $stats['days_since_join'] = (int) now()->diffInDays(Carbon::parse($user->created_at));
            }
        } catch (\Exception $e) {
            // 忽略
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
