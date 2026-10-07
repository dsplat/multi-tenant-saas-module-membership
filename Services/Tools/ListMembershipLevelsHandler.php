<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Membership\Services\MembershipService;

/**
 * AI 工具 handler：获取会员等级列表（L1 只读）。
 */
class ListMembershipLevelsHandler implements ToolHandlerContract
{
    public function __construct(private readonly MembershipService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->getLevels($tenantId);
    }
}
