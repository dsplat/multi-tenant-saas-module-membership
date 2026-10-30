<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Membership\Services\PointsRuleService;

/**
 * AI 工具 handler：手动调整用户积分（L2 写，需用户确认）。
 *
 * 租户隔离由 __invoke 的 $tenantId 显式传入（契约要求），故转调服务时把 $tenantId 作为首参。
 */
class AdjustPointsHandler implements ToolHandlerContract
{
    public function __construct(private readonly PointsRuleService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->adjustPoints(
            $tenantId,
            (int) $arguments['user_id'],
            (int) $arguments['amount'],
            (string) $arguments['reason'],
        );
    }
}
