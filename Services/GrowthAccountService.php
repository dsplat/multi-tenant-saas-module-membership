<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Membership\Models\MemberAccount;
use MultiTenantSaas\Modules\Membership\Models\MemberGrowthLog;

/**
 * 会员成长账户服务。
 *
 * 成长值由业务事件驱动，当前策略只增不减。同一业务事件通过
 * (tenant_id, idempotency_key) 唯一约束保证只入账一次；先插入再依赖唯一键，
 * 不做「先查后插」的并发竞态处理。
 */
class GrowthAccountService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
        protected MembershipService $membershipService,
    ) {}

    public function getAccount(int $tenantId, int $userId): MemberAccount
    {
        TenantContext::assertTenantId($tenantId);

        return MemberAccount::firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $userId],
            ['growth_value' => 0],
        );
    }

    /**
     * 记录一次成长信号。
     *
     * @return array{account: MemberAccount, growth_value: int, recorded: bool, level: array|null}
     */
    public function record(
        int $tenantId,
        int $userId,
        int $changeAmount,
        string $sourceType,
        string $sourceId,
        array $context = [],
        ?string $idempotencyKey = null,
    ): array {
        TenantContext::assertTenantId($tenantId);

        if ($changeAmount <= 0) {
            $account = $this->getAccount($tenantId, $userId);

            return [
                'account' => $account,
                'growth_value' => (int) $account->growth_value,
                'recorded' => false,
                'level' => null,
            ];
        }

        $idempotencyKey ??= $sourceType.':'.$sourceId;
        $now = now()->toDateTimeString();

        try {
            $result = DB::transaction(function () use (
                $tenantId,
                $userId,
                $changeAmount,
                $sourceType,
                $sourceId,
                $context,
                $idempotencyKey,
                $now,
            ): array {
                $account = MemberAccount::firstOrCreate(
                    ['tenant_id' => $tenantId, 'user_id' => $userId],
                    ['growth_value' => 0],
                );

                $account = MemberAccount::where('member_account_id', $account->member_account_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = DB::table('member_growth_logs')
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return [
                        'account' => $account,
                        'growth_value' => (int) $account->growth_value,
                        'recorded' => false,
                    ];
                }

                $newBalance = (int) $account->growth_value + $changeAmount;

                $account->update([
                    'growth_value' => $newBalance,
                    'last_signal_at' => $now,
                ]);

                MemberGrowthLog::create([
                    'member_growth_log_id' => $this->idGenerator->generate(),
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'change_amount' => $changeAmount,
                    'balance_after' => $newBalance,
                    'idempotency_key' => $idempotencyKey,
                    'context' => $context ?: null,
                ]);

                return [
                    'account' => $account->fresh(),
                    'growth_value' => $newBalance,
                    'recorded' => true,
                ];
            });
        } catch (QueryException $e) {
            // 并发重放：另一事务已用同一幂等键写入，本次回滚后按未记录返回。
            if ($this->isDuplicateKey($e)) {
                $account = $this->getAccount($tenantId, $userId);

                return [
                    'account' => $account,
                    'growth_value' => (int) $account->growth_value,
                    'recorded' => false,
                    'level' => null,
                ];
            }

            throw $e;
        }

        $level = null;

        try {
            $level = $this->membershipService->reconcileLevelWithGrowth($tenantId, $userId);
        } catch (\Throwable $e) {
            // 成长值已入账，等级投影失败不能反向破坏业务事件；保留日志供补算。
            Log::warning('[Membership] 自动等级重算失败', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            ...$result,
            'level' => $level,
        ];
    }

    /**
     * 成长流水（C 端/Console 查询）。
     */
    public function history(int $tenantId, int $userId, int $perPage = 20): array
    {
        TenantContext::assertTenantId($tenantId);

        $paginator = MemberGrowthLog::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('member_growth_log_id')
            ->paginate($perPage);

        return [
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ];
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'member_growth_logs_idempotency_unique')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed');
    }
}
