<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 数字成就定义（member_achievements）
 *
 * condition_type 决定从哪些成长信号累计进度；consume_amount 的目标值与事件金额
 * 一样是整数分。reward_type 支持积分、证书、积分+证书或无奖励。
 */
class MemberAchievement extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;
    use SerializesFriendlyDates;

    public const CONDITION_CONSUME_AMOUNT = 'consume_amount';

    public const CONDITION_CONSUME_COUNT = 'consume_count';

    public const CONDITION_INVITE_COUNT = 'invite_count';

    public const CONDITION_SIGN_IN_STREAK = 'sign_in_streak';

    public const CONDITION_POINTS_TOTAL = 'points_total';

    public const CONDITION_COURSE_COMPLETED = 'course_completed';

    public const CONDITION_EXAM_PASSED = 'exam_passed';

    public const CONDITIONS = [
        self::CONDITION_CONSUME_AMOUNT,
        self::CONDITION_CONSUME_COUNT,
        self::CONDITION_INVITE_COUNT,
        self::CONDITION_SIGN_IN_STREAK,
        self::CONDITION_POINTS_TOTAL,
        self::CONDITION_COURSE_COMPLETED,
        self::CONDITION_EXAM_PASSED,
    ];

    /** 累计型条件：信号每次累加 */
    public const CUMULATIVE_CONDITIONS = [
        self::CONDITION_CONSUME_AMOUNT,
        self::CONDITION_CONSUME_COUNT,
        self::CONDITION_INVITE_COUNT,
        self::CONDITION_COURSE_COMPLETED,
        self::CONDITION_EXAM_PASSED,
    ];

    /** 观测型条件：取信号给出的当前值（连续签到取最大值） */
    public const OBSERVED_CONDITIONS = [
        self::CONDITION_SIGN_IN_STREAK,
        self::CONDITION_POINTS_TOTAL,
    ];

    public const REWARD_POINTS = 'points';

    public const REWARD_CERTIFICATE = 'certificate';

    public const REWARD_POINTS_AND_CERTIFICATE = 'points_and_certificate';

    public const REWARD_NONE = 'none';

    public const REWARDS = [
        self::REWARD_POINTS,
        self::REWARD_CERTIFICATE,
        self::REWARD_POINTS_AND_CERTIFICATE,
        self::REWARD_NONE,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $primaryKey = 'member_achievement_id';

    protected $fillable = [
        'tenant_id', 'code', 'name', 'description', 'icon',
        'condition_type', 'condition_value', 'reward_type', 'points_reward',
        'reward_config', 'status', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'condition_value' => 'integer',
            'points_reward' => 'integer',
            'reward_config' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function hasCertificateReward(): bool
    {
        return in_array($this->reward_type, [self::REWARD_CERTIFICATE, self::REWARD_POINTS_AND_CERTIFICATE], true);
    }

    public function hasPointsReward(): bool
    {
        return in_array($this->reward_type, [self::REWARD_POINTS, self::REWARD_POINTS_AND_CERTIFICATE], true);
    }
}
