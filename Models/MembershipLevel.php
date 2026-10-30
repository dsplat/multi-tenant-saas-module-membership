<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 会员等级（membership_levels）
 *
 * 升级/保级条件与权益规则均 JSON 化（upgrade_conditions / retain_conditions / benefits），
 * 免 schema 变更；条件求值见 MembershipService::evaluateUpgrade()。
 */
class MembershipLevel extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;
    use SerializesFriendlyDates;

    protected $primaryKey = 'membership_level_id';

    protected $fillable = [
        'tenant_id', 'name', 'icon', 'sort_order',
        'upgrade_conditions', 'retain_conditions', 'benefits', 'status',
    ];

    protected function casts(): array
    {
        return [
            'upgrade_conditions' => 'array',
            'retain_conditions' => 'array',
            'benefits' => 'array',
            'sort_order' => 'integer',
        ];
    }
}
