<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 会员成长流水（member_growth_logs）
 *
 * idempotency_key 由事件来源构造，配合唯一索引保证重放不重复入账。
 */
class MemberGrowthLog extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    protected $primaryKey = 'member_growth_log_id';

    protected $fillable = [
        'tenant_id', 'user_id', 'source_type', 'source_id',
        'change_amount', 'balance_after', 'idempotency_key', 'context',
    ];

    protected function casts(): array
    {
        return [
            'change_amount' => 'integer',
            'balance_after' => 'integer',
            'context' => 'array',
        ];
    }
}
