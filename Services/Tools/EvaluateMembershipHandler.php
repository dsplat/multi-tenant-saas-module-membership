<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Membership\Services\MembershipService;

/**
 * AI 工具 handler：评估用户会员等级（L1 只读）。
 */
class EvaluateMembershipHandler implements ToolHandlerContract
{
    public function __construct(private readonly MembershipService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->evaluateUpgrade($tenantId, (int) $arguments['user_id']);
    }
}
