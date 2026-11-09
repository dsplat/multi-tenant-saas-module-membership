<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 积分入账事件。
 *
 * 只作为成就引擎的观测信号（points_total），不承载积分账本；
 * 监听方必须按事件幂等处理，不能反向修改积分余额。
 */
class PointsEarned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly int $points,
        public readonly int $balanceAfter,
        public readonly ?string $description = null,
    ) {}
}
