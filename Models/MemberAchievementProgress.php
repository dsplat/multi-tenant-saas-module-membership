<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 用户成就进度（member_achievement_progress）
 *
 * 一个用户对同一成就只有一条进度；status=completed 后不再回退。
 */
class MemberAchievementProgress extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    protected $primaryKey = 'member_achievement_progress_id';

    protected $fillable = [
        'tenant_id', 'user_id', 'member_achievement_id',
        'progress_value', 'target_value', 'status', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_value' => 'integer',
            'target_value' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
