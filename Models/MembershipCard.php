<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 会员卡（membership_cards）
 *
 * discount_rate（折扣率）/ points_multiplier（积分倍率）为**比率列**，保持 decimal，
 * 不属于金额口径（金额铁律只管交易金额/价格/余额）。
 */
class MembershipCard extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;
    use SerializesFriendlyDates;

    protected $primaryKey = 'card_id';

    protected $fillable = [
        'tenant_id', 'name', 'level', 'discount_rate', 'points_multiplier',
        'benefits', 'cover_image', 'description', 'status',
    ];

    protected function casts(): array
    {
        return [
            'benefits' => 'array',
            'discount_rate' => 'decimal:2',
            'points_multiplier' => 'decimal:1',
        ];
    }
}
