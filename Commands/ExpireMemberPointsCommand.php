<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Membership\Services\PointsRuleService;

/**
 * 跨租户积分过期处理。
 *
 * 每个活跃租户显式建立租户上下文后执行，不存在默认租户兜底。
 */
class ExpireMemberPointsCommand extends Command
{
    protected $signature = 'membership:expire-points';

    protected $description = '处理会员积分过期（逐租户执行）';

    public function handle(PointsRuleService $pointsService): int
    {
        $tenants = Tenant::where('status', 'active')
            ->orderBy('tenant_id')
            ->get();

        $total = 0;

        foreach ($tenants as $tenant) {
            $tenantId = (int) $tenant->tenant_id;

            $count = TenantContext::runInTenant((string) $tenantId, function () use ($pointsService, $tenantId): int {
                return $pointsService->expirePoints($tenantId);
            });

            $total += $count;
            $this->line("tenant {$tenantId}: expired {$count}");
        }

        $this->info("expired total: {$total}");

        return self::SUCCESS;
    }
}
