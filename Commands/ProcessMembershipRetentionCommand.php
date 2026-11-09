<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Membership\Models\MemberAccount;
use MultiTenantSaas\Modules\Membership\Services\MembershipService;

/**
 * 跨租户会员保级/等级重算。
 *
 * 当前策略只升级不降级：本命令重放成长值→等级判定，并为运营保留
 * 后续接入降级策略的单一入口。没有成长账户的用户不会被补造。
 */
class ProcessMembershipRetentionCommand extends Command
{
    protected $signature = 'membership:process-retention';

    protected $description = '重算会员等级与保级状态（逐租户执行，只升不降）';

    public function handle(MembershipService $membershipService): int
    {
        $tenants = Tenant::where('status', 'active')
            ->orderBy('tenant_id')
            ->get();

        $changed = 0;

        foreach ($tenants as $tenant) {
            $tenantId = (int) $tenant->tenant_id;

            $changed += TenantContext::runInTenant((string) $tenantId, function () use ($membershipService, $tenantId): int {
                $accounts = MemberAccount::where('tenant_id', $tenantId)
                    ->orderBy('member_account_id')
                    ->cursor();

                $count = 0;

                foreach ($accounts as $account) {
                    if ($membershipService->reconcileLevelWithGrowth($tenantId, (int) $account->user_id) !== null) {
                        $count++;
                    }
                }

                return $count;
            });
        }

        $this->info("level changes: {$changed}");

        return self::SUCCESS;
    }
}
