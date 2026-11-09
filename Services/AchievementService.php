<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Membership\Events\MemberAchievementAwarded;
use MultiTenantSaas\Modules\Membership\Models\MemberAchievement;
use MultiTenantSaas\Modules\Membership\Models\MemberAchievementAward;
use MultiTenantSaas\Modules\Membership\Models\MemberAchievementProgress;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 数字成就引擎。
 *
 * 业务事件只需上报「条件类型 + 增量/观测值」，本服务负责：
 * 进度落库 → 达成判定 → 幂等发奖（积分/证书事件）。
 */
class AchievementService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
        protected PointsRuleService $pointsRuleService,
    ) {}

    // ========== 成就定义 ==========

    public function createDefinition(int $tenantId, array $data): MemberAchievement
    {
        TenantContext::assertTenantId($tenantId);
        $this->validateDefinition($data, true);

        return MemberAchievement::create([
            'member_achievement_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'condition_type' => $data['condition_type'],
            'condition_value' => (int) $data['condition_value'],
            'reward_type' => $data['reward_type'] ?? MemberAchievement::REWARD_POINTS,
            'points_reward' => (int) ($data['points_reward'] ?? 0),
            'reward_config' => $data['reward_config'] ?? null,
            'status' => $data['status'] ?? MemberAchievement::STATUS_ACTIVE,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
    }

    public function updateDefinition(int $tenantId, int $achievementId, array $data): MemberAchievement
    {
        TenantContext::assertTenantId($tenantId);
        $this->validateDefinition($data, false);

        $achievement = $this->getDefinition($tenantId, $achievementId);
        $fillable = [
            'code', 'name', 'description', 'icon', 'condition_type', 'condition_value',
            'reward_type', 'points_reward', 'reward_config', 'status', 'sort_order',
        ];

        $achievement->update(array_intersect_key($data, array_flip($fillable)));

        return $achievement->fresh();
    }

    public function deleteDefinition(int $tenantId, int $achievementId): bool
    {
        TenantContext::assertTenantId($tenantId);

        return (bool) $this->getDefinition($tenantId, $achievementId)->delete();
    }

    public function getDefinition(int $tenantId, int $achievementId): MemberAchievement
    {
        TenantContext::assertTenantId($tenantId);

        return MemberAchievement::where('tenant_id', $tenantId)
            ->where('member_achievement_id', $achievementId)
            ->firstOrFail();
    }

    public function listDefinitions(int $tenantId, array $filters = [], int $perPage = 20): array
    {
        TenantContext::assertTenantId($tenantId);

        $query = MemberAchievement::where('tenant_id', $tenantId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }

        $paginator = $query->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->orderByDesc('member_achievement_id')
            ->paginate($perPage);

        return [
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ];
    }

    // ========== 进度与发奖 ==========

    /**
     * 上报一条成就信号。
     *
     * @param  string  $conditionType  MemberAchievement::CONDITION_*
     * @param  int  $increment  累计型条件的增量
     * @param  int|null  $observedValue  观测型条件的当前值（连续签到/积分余额）
     */
    public function recordSignal(
        int $tenantId,
        ?int $userId,
        string $conditionType,
        int $increment = 0,
        ?int $observedValue = null,
        string $sourceType = 'signal',
        string $sourceId = '',
        array $context = [],
    ): array {
        TenantContext::assertTenantId($tenantId);

        if ($userId === null || ! in_array($conditionType, MemberAchievement::CONDITIONS, true)) {
            return ['completed' => [], 'evaluated' => 0];
        }

        $achievements = MemberAchievement::where('tenant_id', $tenantId)
            ->where('condition_type', $conditionType)
            ->where('status', MemberAchievement::STATUS_ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('member_achievement_id')
            ->get();

        if ($achievements->isEmpty()) {
            return ['completed' => [], 'evaluated' => 0];
        }

        $completed = [];

        foreach ($achievements as $achievement) {
            try {
                $award = $this->applySignal(
                    $tenantId,
                    $userId,
                    $achievement,
                    $increment,
                    $observedValue,
                    $sourceType,
                    $sourceId,
                    $context,
                );

                if ($award !== null) {
                    $completed[] = [
                        'achievement_id' => (int) $achievement->member_achievement_id,
                        'code' => $achievement->code,
                        'award_id' => (int) $award->member_achievement_award_id,
                    ];
                }
            } catch (\Throwable $e) {
                // 单个成就配置异常不吞掉其它成就，也不阻断来源业务事件。
                Log::warning('[Membership] 成就信号处理失败', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'achievement_id' => $achievement->member_achievement_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['completed' => $completed, 'evaluated' => $achievements->count()];
    }

    /**
     * C 端成就墙：含未完成、已完成和奖励记录。
     */
    public function listForUser(int $tenantId, int $userId): array
    {
        TenantContext::assertTenantId($tenantId);

        $achievements = MemberAchievement::where('tenant_id', $tenantId)
            ->where('status', MemberAchievement::STATUS_ACTIVE)
            ->orderBy('sort_order')
            ->orderByDesc('member_achievement_id')
            ->get();

        $progressRows = MemberAchievementProgress::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->get()
            ->keyBy('member_achievement_id');

        $awardRows = MemberAchievementAward::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->get()
            ->keyBy('member_achievement_id');

        return $achievements->map(function (MemberAchievement $achievement) use ($progressRows, $awardRows): array {
            $progress = $progressRows->get($achievement->member_achievement_id);
            $award = $awardRows->get($achievement->member_achievement_id);

            return [
                'achievement_id' => (int) $achievement->member_achievement_id,
                'code' => $achievement->code,
                'name' => $achievement->name,
                'description' => $achievement->description,
                'icon' => $achievement->icon,
                'condition_type' => $achievement->condition_type,
                'condition_value' => (int) $achievement->condition_value,
                'reward_type' => $achievement->reward_type,
                'points_reward' => (int) $achievement->points_reward,
                'progress_value' => (int) ($progress->progress_value ?? 0),
                'target_value' => (int) ($progress->target_value ?? $achievement->condition_value),
                'status' => $progress->status ?? MemberAchievementProgress::STATUS_PENDING,
                'completed_at' => $progress?->completed_at,
                'awarded_at' => $award?->awarded_at,
                'award_id' => $award ? (int) $award->member_achievement_award_id : null,
            ];
        })->all();
    }

    /**
     * Console 发奖记录。
     */
    public function listAwards(int $tenantId, ?int $achievementId = null, int $perPage = 20): array
    {
        TenantContext::assertTenantId($tenantId);

        $query = MemberAchievementAward::where('tenant_id', $tenantId);

        if ($achievementId !== null) {
            $query->where('member_achievement_id', $achievementId);
        }

        $paginator = $query->orderByDesc('awarded_at')
            ->orderByDesc('member_achievement_award_id')
            ->paginate($perPage);

        return [
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ];
    }

    private function applySignal(
        int $tenantId,
        int $userId,
        MemberAchievement $achievement,
        int $increment,
        ?int $observedValue,
        string $sourceType,
        string $sourceId,
        array $context,
    ): ?MemberAchievementAward {
        $result = DB::transaction(function () use (
            $tenantId,
            $userId,
            $achievement,
            $increment,
            $observedValue,
            $sourceType,
            $sourceId,
            $context,
        ): ?MemberAchievementAward {
            $progress = $this->firstOrCreateProgress($tenantId, $userId, $achievement);

            $progress = MemberAchievementProgress::where(
                'member_achievement_progress_id',
                $progress->member_achievement_progress_id
            )->lockForUpdate()->firstOrFail();

            $target = max(1, (int) $achievement->condition_value);
            $current = (int) $progress->progress_value;
            $isCumulative = in_array($achievement->condition_type, MemberAchievement::CUMULATIVE_CONDITIONS, true);
            $newProgress = $isCumulative
                ? $current + max(0, $increment)
                : max($current, (int) ($observedValue ?? $increment));

            $newProgress = min($target, $newProgress);
            $justCompleted = $newProgress >= $target
                && $progress->status !== MemberAchievementProgress::STATUS_COMPLETED;

            $progress->update([
                'progress_value' => $newProgress,
                'target_value' => $target,
                'status' => $justCompleted
                    ? MemberAchievementProgress::STATUS_COMPLETED
                    : $progress->status,
                'completed_at' => $justCompleted ? now() : $progress->completed_at,
            ]);

            if (! $justCompleted) {
                return null;
            }

            $awardId = $this->idGenerator->generate();
            $awarded = DB::table('member_achievement_awards')->insertOrIgnore([
                'member_achievement_award_id' => $awardId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'member_achievement_id' => $achievement->member_achievement_id,
                'reward_type' => $achievement->reward_type,
                'reward_value' => $achievement->hasPointsReward()
                    ? (int) $achievement->points_reward
                    : (int) ($achievement->reward_config['certificate_id'] ?? 0),
                'idempotency_key' => 'achievement:'.$achievement->member_achievement_id.':user:'.$userId,
                'context' => json_encode(array_merge($context, [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                ]), JSON_UNESCAPED_UNICODE),
                'awarded_at' => now()->toDateTimeString(),
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            if ($awarded === 0) {
                return null;
            }

            if ($achievement->hasPointsReward() && (int) $achievement->points_reward > 0) {
                // 成就奖励不再被会员倍率放大，且事件由本服务在事务提交后统一派发。
                $this->pointsRuleService->earnPoints(
                    $tenantId,
                    $userId,
                    (int) $achievement->points_reward,
                    null,
                    '成就奖励：'.$achievement->name,
                    MemberBenefitService::BASIS_POINTS,
                    false,
                );
            }

            return MemberAchievementAward::where(
                'member_achievement_award_id',
                $awardId
            )->firstOrFail();
        });

        if ($result !== null) {
            event(new MemberAchievementAwarded(
                tenantId: $tenantId,
                userId: $userId,
                achievementId: (int) $achievement->member_achievement_id,
                achievementCode: $achievement->code,
                achievementName: $achievement->name,
                rewardType: $achievement->reward_type,
                rewardValue: (int) $result->reward_value,
                awardId: (int) $result->member_achievement_award_id,
                context: array_merge($achievement->reward_config ?? [], $context),
            ));
        }

        return $result;
    }

    private function firstOrCreateProgress(
        int $tenantId,
        int $userId,
        MemberAchievement $achievement,
    ): MemberAchievementProgress {
        try {
            return MemberAchievementProgress::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'member_achievement_id' => $achievement->member_achievement_id,
                ],
                [
                    'progress_value' => 0,
                    'target_value' => (int) $achievement->condition_value,
                    'status' => MemberAchievementProgress::STATUS_PENDING,
                ],
            );
        } catch (QueryException $e) {
            // 并发首信号：唯一键已由另一事务创建，改读既有进度。
            if (str_contains($e->getMessage(), 'member_achievement_progress_user_unique')
                || str_contains($e->getMessage(), 'Duplicate entry')
                || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                return MemberAchievementProgress::where('tenant_id', $tenantId)
                    ->where('user_id', $userId)
                    ->where('member_achievement_id', $achievement->member_achievement_id)
                    ->firstOrFail();
            }

            throw $e;
        }
    }

    private function validateDefinition(array $data, bool $creating): void
    {
        if ($creating) {
            if (empty($data['code']) || empty($data['name']) || empty($data['condition_type'])) {
                throw new UnprocessableEntityHttpException('code, name and condition_type are required');
            }

            if (! isset($data['condition_value']) || (int) $data['condition_value'] < 1) {
                throw new UnprocessableEntityHttpException('condition_value must be at least 1');
            }
        }

        if (isset($data['condition_type']) && ! in_array($data['condition_type'], MemberAchievement::CONDITIONS, true)) {
            throw new UnprocessableEntityHttpException('Invalid condition_type');
        }

        if (isset($data['condition_value']) && (int) $data['condition_value'] < 1) {
            throw new UnprocessableEntityHttpException('condition_value must be at least 1');
        }

        if (isset($data['reward_type']) && ! in_array($data['reward_type'], MemberAchievement::REWARDS, true)) {
            throw new UnprocessableEntityHttpException('Invalid reward_type');
        }

        if (isset($data['status']) && ! in_array($data['status'], [MemberAchievement::STATUS_ACTIVE, MemberAchievement::STATUS_INACTIVE], true)) {
            throw new UnprocessableEntityHttpException('Invalid status');
        }

        if (isset($data['points_reward']) && (int) $data['points_reward'] < 0) {
            throw new UnprocessableEntityHttpException('points_reward cannot be negative');
        }
    }
}
