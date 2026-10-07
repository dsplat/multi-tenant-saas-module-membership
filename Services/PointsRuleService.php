<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Membership\Models\PointsRule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 积分规则服务（Console / 通用积分引擎）
 *
 * 规则 CRUD + 积分获取/消耗/调整/过期（事务 + 行锁保护）+ 余额/流水查询。
 *
 * 物理归位说明：由 scrm 自建模块提升进框架。按身份铁律「去 customer 口径」——
 * 用户身份一律走框架 user_id（validateUser 校验 users 表，不再引用 Customer）。
 * **未迁入**：积分商城编排（points_products 表 + createProduct/exchangeProduct），
 * 属 SCRM 特有，留在项目层（见 docs/decisions/2026-10-07-channel-membership-lift-evaluation.md）。
 */
class PointsRuleService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    // ========== 积分规则 CRUD ==========

    public function createRule($tenantId, array $data): PointsRule
    {
        TenantContext::setTenantId((string) $tenantId);

        return PointsRule::create([
            'points_rule_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'trigger_type' => $data['trigger_type'],
            'points' => $data['points'] ?? 0,
            'conditions' => $data['conditions'] ?? null,
            'daily_limit' => $data['daily_limit'] ?? 0,
            'total_limit' => $data['total_limit'] ?? 0,
            'status' => $data['status'] ?? 'active',
        ]);
    }

    public function getRules($tenantId, $perPage = 20): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $paginator = PointsRule::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->orderByDesc('points_rule_id')
            ->paginate($perPage);

        return [
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    public function updateRule($tenantId, $ruleId, array $data): PointsRule
    {
        TenantContext::setTenantId((string) $tenantId);

        $rule = PointsRule::where('points_rule_id', $ruleId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
        $fillable = ['name', 'trigger_type', 'points', 'conditions', 'daily_limit', 'total_limit', 'status'];
        $rule->update(array_intersect_key($data, array_flip($fillable)));

        $fresh = $rule->fresh();
        if (! $fresh) {
            throw new DomainException('Failed to refresh rule after update');
        }

        return $fresh;
    }

    public function toggleRule($tenantId, $ruleId): PointsRule
    {
        TenantContext::setTenantId((string) $tenantId);

        $rule = PointsRule::where('points_rule_id', $ruleId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $rule->status = $rule->status === 'active' ? 'inactive' : 'active';
        $rule->save();

        return $rule->fresh();
    }

    public function getPointsLogs($tenantId, ?string $userId = null, ?string $type = null, $perPage = 20): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = DB::table('points_transactions')
            ->where('tenant_id', $tenantId);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        if ($type !== null) {
            $query->where('type', $type);
        }

        $paginator = $query->orderByDesc('created_at')
            ->orderByDesc('points_transaction_id')
            ->paginate($perPage);

        return [
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ];
    }

    // ========== 积分获取/消耗（事务+锁保护） ==========

    public function earnPoints($tenantId, $userId, $points, $ruleId = null, ?string $description = null): array
    {
        $this->validateUser($tenantId, $userId);

        if ($points <= 0) {
            throw new UnprocessableEntityHttpException('Points must be positive');
        }

        return DB::transaction(function () use ($tenantId, $userId, $points, $ruleId, $description) {
            $balance = $this->getBalanceLocked($tenantId, $userId);
            $newBalance = $balance + $points;

            $id = $this->idGenerator->generate();
            DB::table('points_transactions')->insert([
                'points_transaction_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'type' => 'earn',
                'amount' => $points,
                'balance_after' => $newBalance,
                'rule_id' => $ruleId,
                'description' => $description,
                'expires_at' => now()->addDays(365)->toDateTimeString(),
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            return ['id' => $id, 'type' => 'earn', 'amount' => $points, 'balance_after' => $newBalance];
        });
    }

    public function consumePoints($tenantId, $userId, $points, ?string $relatedType = null, $relatedId = null, ?string $description = null): array
    {
        $this->validateUser($tenantId, $userId);

        if ($points <= 0) {
            throw new UnprocessableEntityHttpException('Points must be positive');
        }

        return DB::transaction(function () use ($tenantId, $userId, $points, $relatedType, $relatedId, $description) {
            $balance = $this->getBalanceLocked($tenantId, $userId);

            if ($balance < $points) {
                throw new UnprocessableEntityHttpException('Insufficient points balance');
            }

            $newBalance = $balance - $points;

            $id = $this->idGenerator->generate();
            DB::table('points_transactions')->insert([
                'points_transaction_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'type' => 'consume',
                'amount' => -$points,
                'balance_after' => $newBalance,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'description' => $description,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            return ['id' => $id, 'type' => 'consume', 'amount' => -$points, 'balance_after' => $newBalance];
        });
    }

    public function adjustPoints($tenantId, $userId, $points, string $description): array
    {
        $this->validateUser($tenantId, $userId);

        return DB::transaction(function () use ($tenantId, $userId, $points, $description) {
            $balance = $this->getBalanceLocked($tenantId, $userId);
            $newBalance = $balance + $points;

            if ($newBalance < 0) {
                throw new UnprocessableEntityHttpException('Adjustment would result in negative balance');
            }

            $id = $this->idGenerator->generate();
            DB::table('points_transactions')->insert([
                'points_transaction_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'type' => 'adjust',
                'amount' => $points,
                'balance_after' => $newBalance,
                'description' => $description,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            return ['id' => $id, 'type' => 'adjust', 'amount' => $points, 'balance_after' => $newBalance];
        });
    }

    // ========== 用户积分查询 ==========

    public function getPointsBalance($tenantId, $userId): int
    {
        $this->validateUser($tenantId, $userId);

        $lastTx = DB::table('points_transactions')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('points_transaction_id')
            ->first();

        return $lastTx ? (int) $lastTx->balance_after : 0;
    }

    public function getPointsFlow($tenantId, $userId, ?string $type = null, $perPage = 20): array
    {
        $this->validateUser($tenantId, $userId);

        $query = DB::table('points_transactions')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId);

        if ($type !== null) {
            $query->where('type', $type);
        }

        $paginator = $query->orderByDesc('created_at')
            ->orderByDesc('points_transaction_id')
            ->paginate($perPage);

        return [
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ];
    }

    // ========== 积分过期（按用户分组处理） ==========

    public function expirePoints($tenantId): int
    {
        TenantContext::setTenantId((string) $tenantId);

        $expiredTxs = DB::table('points_transactions')
            ->where('tenant_id', $tenantId)
            ->where('type', 'earn')
            ->where('expired', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->toDateTimeString())
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->orderBy('points_transaction_id')
            ->get();

        // 按用户分组
        $grouped = [];
        foreach ($expiredTxs as $tx) {
            $uid = (int) $tx->user_id;
            $grouped[$uid][] = $tx;
        }

        $expiredCount = 0;

        foreach ($grouped as $userId => $txs) {
            DB::transaction(function () use ($tenantId, $userId, $txs, &$expiredCount) {
                $balance = $this->getBalanceLocked($tenantId, $userId);

                foreach ($txs as $tx) {
                    $expireAmount = min((int) $tx->amount, $balance);

                    if ($expireAmount > 0) {
                        $balance -= $expireAmount;

                        DB::table('points_transactions')->insert([
                            'points_transaction_id' => $this->idGenerator->generate(),
                            'tenant_id' => $tenantId,
                            'user_id' => $userId,
                            'type' => 'expire',
                            'amount' => -$expireAmount,
                            'balance_after' => $balance,
                            'description' => '积分过期',
                            'created_at' => now()->toDateTimeString(),
                            'updated_at' => now()->toDateTimeString(),
                        ]);

                        DB::table('points_transactions')
                            ->where('points_transaction_id', $tx->points_transaction_id)
                            ->update(['expired' => true, 'updated_at' => now()->toDateTimeString()]);

                        $expiredCount++;
                    }
                }
            });
        }

        return $expiredCount;
    }

    // ========== 私有方法 ==========

    private function validateUser($tenantId, $userId): void
    {
        TenantContext::setTenantId((string) $tenantId);

        $exists = DB::table('users')
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $exists) {
            throw new NotFoundHttpException("User [{$userId}] not found");
        }
    }

    /**
     * 在事务内获取余额（带行锁防并发）
     */
    private function getBalanceLocked($tenantId, $userId): int
    {
        $lastTx = DB::table('points_transactions')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('points_transaction_id')
            ->lockForUpdate()
            ->first();

        return $lastTx ? (int) $lastTx->balance_after : 0;
    }
}
