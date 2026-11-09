<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Course\Events\CourseCompleted;
use MultiTenantSaas\Modules\Membership\Services\MembershipSignalService;

class CourseCompletedMembershipListener
{
    public function __construct(
        protected MembershipSignalService $signalService,
    ) {}

    public function handle(CourseCompleted $event): void
    {
        try {
            $this->signalService->handleCourseCompleted($event);
        } catch (\Throwable $e) {
            Log::warning('[Membership] CourseCompleted 信号处理失败', [
                'tenant_id' => $event->tenantId,
                'course_id' => $event->courseId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
