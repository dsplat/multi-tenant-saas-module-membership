<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 积分规则（points_rules）
 *
 * trigger_type 触发场景（purchase/sign_in/referral/manual/birthday）；points 为积分数
 * （非金额，积分铁律不适用）；conditions 为 JSON 化的额外约束。
 */
class PointsRule extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;
    use SerializesFriendlyDates;

    protected $primaryKey = 'points_rule_id';

    protected $fillable = [
        'tenant_id', 'name', 'trigger_type', 'points',
        'conditions', 'daily_limit', 'total_limit', 'status',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'points' => 'integer',
            'daily_limit' => 'integer',
            'total_limit' => 'integer',
        ];
    }
}
