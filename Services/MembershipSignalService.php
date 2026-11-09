<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Course\Events\CourseCompleted;
use MultiTenantSaas\Modules\Exam\Events\ExamPassed;
use MultiTenantSaas\Modules\Membership\Events\PointsEarned;
use MultiTenantSaas\Modules\Membership\Models\MemberAchievement;
use MultiTenantSaas\Modules\Order\Events\OrderPaid;

/**
 * 业务事件 → 会员成长/成就信号的统一适配层。
 *
 * 事件监听器只调用本服务；所有跨模块事件均为可选依赖，模块未安装时不会注册。
 * 下游专有事件（如 SCRM 签到）由下游调用 handleCheckInCompleted() 桥接，
 * 框架不反向依赖项目事件类。
 */
class MembershipSignalService
{
    public function __construct(
        protected GrowthAccountService $growthAccountService,
        protected AchievementService $achievementService,
    ) {}

    public function handleOrderPaid(OrderPaid $event): void
    {
        TenantContext::assertTenantId($event->tenantId);

        if ($event->buyerUserId === null) {
            return;
        }

        $userId = $event->buyerUserId;
        $amount = max(0, $event->amount);
        $perYuan = max(0, (int) config('membership.growth.order_paid_per_yuan', 1));
        $minimum = max(0, (int) config('membership.growth.order_paid_minimum', 1));
        $growth = $amount > 0 ? max($minimum, intdiv($amount, 100) * $perYuan) : 0;

        if ($growth > 0) {
            $this->growthAccountService->record(
                $event->tenantId,
                $userId,
                $growth,
                'order_paid',
                (string) $event->orderId,
                [
                    'order_no' => $event->orderNo,
                    'amount_fen' => $amount,
                ],
            );
        }

        if ($amount > 0) {
            $this->achievementService->recordSignal(
                $event->tenantId,
                $userId,
                MemberAchievement::CONDITION_CONSUME_AMOUNT,
                $amount,
                null,
                'order_paid',
                (string) $event->orderId,
                ['order_no' => $event->orderNo],
            );
        }

        $this->achievementService->recordSignal(
            $event->tenantId,
            $userId,
            MemberAchievement::CONDITION_CONSUME_COUNT,
            1,
            null,
            'order_paid',
            (string) $event->orderId,
            ['order_no' => $event->orderNo],
        );
    }

    public function handleCourseCompleted(CourseCompleted $event): void
    {
        TenantContext::assertTenantId($event->tenantId);

        $growth = max(0, (int) config('membership.growth.course_completed', 20));

        if ($growth > 0) {
            $this->growthAccountService->record(
                $event->tenantId,
                $event->userId,
                $growth,
                'course_completed',
                (string) $event->courseId,
                ['course_title' => $event->courseTitle],
            );
        }

        $this->achievementService->recordSignal(
            $event->tenantId,
            $event->userId,
            MemberAchievement::CONDITION_COURSE_COMPLETED,
            1,
            null,
            'course_completed',
            (string) $event->courseId,
            ['course_title' => $event->courseTitle],
        );
    }

    public function handleExamPassed(ExamPassed $event): void
    {
        TenantContext::assertTenantId($event->tenantId);

        $growth = max(0, (int) config('membership.growth.exam_passed', 10));

        if ($growth > 0) {
            $this->growthAccountService->record(
                $event->tenantId,
                $event->userId,
                $growth,
                'exam_passed',
                (string) $event->examId,
                ['exam_title' => $event->examTitle, 'score' => $event->score],
            );
        }

        $this->achievementService->recordSignal(
            $event->tenantId,
            $event->userId,
            MemberAchievement::CONDITION_EXAM_PASSED,
            1,
            null,
            'exam_passed',
            (string) $event->examId,
            ['exam_title' => $event->examTitle, 'score' => $event->score],
        );
    }

    /**
     * 下游签到事件桥接入口（框架不引用项目事件类）。
     */
    public function handleCheckInCompleted(
        int $tenantId,
        int $userId,
        int $activityId,
        int $streakCount = 0,
    ): void {
        TenantContext::assertTenantId($tenantId);

        $growth = max(0, (int) config('membership.growth.check_in', 2));

        if ($growth > 0) {
            $this->growthAccountService->record(
                $tenantId,
                $userId,
                $growth,
                'check_in',
                (string) $activityId.':'.now()->toDateString(),
                ['activity_id' => $activityId, 'streak_count' => $streakCount],
            );
        }

        $this->achievementService->recordSignal(
            $tenantId,
            $userId,
            MemberAchievement::CONDITION_SIGN_IN_STREAK,
            0,
            max(0, $streakCount),
            'check_in',
            (string) $activityId,
            ['activity_id' => $activityId, 'streak_count' => $streakCount],
        );
    }

    public function handlePointsEarned(PointsEarned $event): void
    {
        TenantContext::assertTenantId($event->tenantId);

        $this->achievementService->recordSignal(
            $event->tenantId,
            $event->userId,
            MemberAchievement::CONDITION_POINTS_TOTAL,
            0,
            max(0, $event->balanceAfter),
            'points_earned',
            (string) $event->points,
            ['description' => $event->description],
        );
    }
}
