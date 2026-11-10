<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 会员成长账户（member_accounts）
 *
 * growth_value 为成长值（只增不减），current_level_id 是会员等级的物化投影。
 */
class MemberAccount extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    protected $primaryKey = 'member_account_id';

    protected $fillable = [
        'tenant_id', 'user_id', 'growth_value', 'current_level_id',
        'level_status', 'level_started_at', 'level_expires_at', 'last_signal_at',
    ];

    protected function casts(): array
    {
        return [
            'growth_value' => 'integer',
            'current_level_id' => 'integer',
            'level_started_at' => 'datetime',
            'level_expires_at' => 'datetime',
            'last_signal_at' => 'datetime',
        ];
    }
}
