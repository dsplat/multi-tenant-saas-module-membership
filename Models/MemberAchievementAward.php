<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 成就发奖记录（member_achievement_awards）
 *
 * idempotency_key 与唯一索引是奖励只发一次的事务性保证。
 */
class MemberAchievementAward extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    protected $primaryKey = 'member_achievement_award_id';

    protected $fillable = [
        'tenant_id', 'user_id', 'member_achievement_id',
        'reward_type', 'reward_value', 'idempotency_key', 'context', 'awarded_at',
    ];

    protected function casts(): array
    {
        return [
            'reward_value' => 'integer',
            'context' => 'array',
            'awarded_at' => 'datetime',
        ];
    }
}
