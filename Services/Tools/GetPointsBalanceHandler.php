<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Membership\Services\PointsRuleService;

/**
 * AI 工具 handler：查询用户积分余额（L1 只读）。
 */
class GetPointsBalanceHandler implements ToolHandlerContract
{
    public function __construct(private readonly PointsRuleService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->getPointsBalance($tenantId, (int) $arguments['user_id']);
    }
}
