<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Exam\Events\ExamPassed;
use MultiTenantSaas\Modules\Membership\Services\MembershipSignalService;

class ExamPassedMembershipListener
{
    public function __construct(
        protected MembershipSignalService $signalService,
    ) {}

    public function handle(ExamPassed $event): void
    {
        try {
            $this->signalService->handleExamPassed($event);
        } catch (\Throwable $e) {
            Log::warning('[Membership] ExamPassed 信号处理失败', [
                'tenant_id' => $event->tenantId,
                'exam_id' => $event->examId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
