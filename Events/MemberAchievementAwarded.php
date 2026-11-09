<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 成就发奖完成事件。
 *
 * 框架只负责积分奖励；证书等外部奖励由下游监听本事件后自行发放。
 * 事件携带成就和奖项快照，监听方以 awardId 作为幂等键。
 */
class MemberAchievementAwarded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly int $achievementId,
        public readonly string $achievementCode,
        public readonly string $achievementName,
        public readonly string $rewardType,
        public readonly int $rewardValue,
        public readonly int $awardId,
        public readonly array $context = [],
    ) {}
}
