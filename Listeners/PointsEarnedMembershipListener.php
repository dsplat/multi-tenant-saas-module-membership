<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Membership\Events\PointsEarned;
use MultiTenantSaas\Modules\Membership\Services\MembershipSignalService;

class PointsEarnedMembershipListener
{
    public function __construct(
        protected MembershipSignalService $signalService,
    ) {}

    public function handle(PointsEarned $event): void
    {
        try {
            $this->signalService->handlePointsEarned($event);
        } catch (\Throwable $e) {
            Log::warning('[Membership] PointsEarned 成就进度处理失败', [
                'tenant_id' => $event->tenantId,
                'user_id' => $event->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
