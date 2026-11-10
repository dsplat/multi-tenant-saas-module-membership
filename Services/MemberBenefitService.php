<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Services;

use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Membership\Models\MemberAccount;
use MultiTenantSaas\Modules\Membership\Models\MembershipCard;
use MultiTenantSaas\Modules\Membership\Models\MembershipLevel;

/**
 * 会员权益解析。
 *
 * 权益优先取当前等级 benefits：
 * - discount_rate：0~1 的折扣率（1 = 原价）
 * - points_multiplier：积分倍率（1 = 不加成）
 *
 * 未在等级 benefits 中配置时，回退到同租户 active 会员卡（card.level 匹配等级名称
 * 或等级 ID）的 discount_rate / points_multiplier 列，使既有会员卡配置可继续生效。
 *
 * 折扣金额全程使用整数分；比率只转换为万分比（basis points）参与整数运算。
 */
class MemberBenefitService
{
    public const BASIS_POINTS = 10000;

    public function getBenefits(int $tenantId, ?int $userId): array
    {
        TenantContext::assertTenantId($tenantId);

        $empty = [
            'level_id' => null,
            'level_name' => null,
            'discount_basis_points' => self::BASIS_POINTS,
            'points_multiplier_basis_points' => self::BASIS_POINTS,
        ];

        if ($userId === null || ! Schema::hasTable('member_accounts')) {
            return $empty;
        }

        $account = MemberAccount::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();

        if (! $account || ! $account->current_level_id) {
            return $empty;
        }

        if ($account->level_status === 'expired'
            || ($account->level_status === 'active'
                && $account->level_expires_at
                && ! $account->level_expires_at->isFuture())) {
            return $empty;
        }

        $level = MembershipLevel::where('tenant_id', $tenantId)
            ->where('membership_level_id', $account->current_level_id)
            ->first();

        if (! $level) {
            return $empty;
        }

        $benefits = $level->benefits ?? [];
        $card = $this->resolveCard($tenantId, $level);

        $discountRate = $benefits['discount_rate']
            ?? $benefits['discount']
            ?? $card?->discount_rate;
        $pointsMultiplier = $benefits['points_multiplier']
            ?? $card?->points_multiplier;

        return [
            'level_id' => (int) $level->membership_level_id,
            'level_name' => $level->name,
            'discount_basis_points' => $this->discountToBasisPoints($discountRate),
            'points_multiplier_basis_points' => $this->multiplierToBasisPoints($pointsMultiplier),
        ];
    }

    public function cashDiscountBasisPoints(int $tenantId, ?int $userId): int
    {
        return (int) $this->getBenefits($tenantId, $userId)['discount_basis_points'];
    }

    public function pointsMultiplierBasisPoints(int $tenantId, ?int $userId): int
    {
        return (int) $this->getBenefits($tenantId, $userId)['points_multiplier_basis_points'];
    }

    /**
     * 按万分比折扣整数分，四舍五入到分。
     */
    public function applyDiscount(int $amountFen, int $discountBasisPoints): int
    {
        if ($amountFen <= 0 || $discountBasisPoints >= self::BASIS_POINTS) {
            return $amountFen;
        }

        $discountBasisPoints = max(0, min(self::BASIS_POINTS, $discountBasisPoints));

        return intdiv($amountFen * $discountBasisPoints + intdiv(self::BASIS_POINTS, 2), self::BASIS_POINTS);
    }

    private function resolveCard(int $tenantId, MembershipLevel $level): ?MembershipCard
    {
        if (! Schema::hasTable('membership_cards')) {
            return null;
        }

        return MembershipCard::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where(function ($query) use ($level): void {
                $query->where('level', $level->name)
                    ->orWhere('level', (string) $level->membership_level_id);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('card_id')
            ->first();
    }

    private function discountToBasisPoints(mixed $rate): int
    {
        if (! is_numeric($rate)) {
            return self::BASIS_POINTS;
        }

        $value = (float) $rate;

        if ($value <= 0 || $value > 1) {
            return self::BASIS_POINTS;
        }

        return max(1, (int) round($value * self::BASIS_POINTS));
    }

    private function multiplierToBasisPoints(mixed $rate): int
    {
        if (! is_numeric($rate)) {
            return self::BASIS_POINTS;
        }

        $value = (float) $rate;

        if ($value <= 0) {
            return self::BASIS_POINTS;
        }

        return max(self::BASIS_POINTS, min(100000, (int) round($value * self::BASIS_POINTS)));
    }
}
