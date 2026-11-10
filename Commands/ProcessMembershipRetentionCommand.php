<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Membership\Services\MembershipService;

/**
 * 跨租户会员生命周期：到期续期/过期 + 成长升级。
 *
 * 有有效期的等级到期前按保级条件自动续期；未满足则降为普通会员并停止自动升级。
 * 无成长账户的用户不会被补造。
 */
class ProcessMembershipRetentionCommand extends Command
{
    protected $signature = 'membership:process-retention';

    protected $description = '会员到期续期/降级与等级重算（逐租户执行）';

    public function handle(MembershipService $membershipService): int
    {
        $tenants = Tenant::where('status', 'active')
            ->orderBy('tenant_id')
            ->get();

        $renewed = 0;
        $expired = 0;
        $upgraded = 0;

        foreach ($tenants as $tenant) {
            $tenantId = (int) $tenant->tenant_id;

            $summary = TenantContext::runInTenant(
                (string) $tenantId,
                fn (): array => $membershipService->processMembershipLifecycle($tenantId),
            );

            $renewed += $summary['renewed'];
            $expired += $summary['expired'];
            $upgraded += $summary['upgraded'];
        }

        $this->info("renewed: {$renewed}, expired: {$expired}, upgraded: {$upgraded}");

        return self::SUCCESS;
    }
}
