<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Membership\Services\AchievementService;
use MultiTenantSaas\Modules\Membership\Services\GrowthAccountService;
use MultiTenantSaas\Modules\Membership\Services\MemberBenefitService;
use MultiTenantSaas\Modules\Membership\Services\MembershipService;

/**
 * C 端会员档案（self-scoped）。
 *
 * 只读当前登录用户自己的成长值、等级、权益和成就，不接收 userId 入参。
 */
class MemberProfileController extends BaseController
{
    public function __construct(
        protected MembershipService $membershipService,
        protected MemberBenefitService $benefitService,
        protected GrowthAccountService $growthService,
        protected AchievementService $achievementService,
    ) {}

    public function profile(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);

        if ($userId === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data' => [
                ...$this->membershipService->getMemberProfile($tenantId, $userId),
                'benefits' => $this->benefitService->getBenefits($tenantId, $userId),
            ],
        ]);
    }

    public function achievements(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);

        if ($userId === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data' => $this->achievementService->listForUser($tenantId, $userId),
        ]);
    }

    public function growth(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);

        if ($userId === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $tenantId = (int) TenantContext::getId();
        $account = $this->growthService->getAccount($tenantId, $userId);
        $history = $this->growthService->history(
            $tenantId,
            $userId,
            (int) ($validated['per_page'] ?? 20),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'growth_value' => (int) $account->growth_value,
                'history' => $history,
            ],
        ]);
    }

    protected function currentUserId(Request $request): ?int
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return (int) $user->getKey();
    }
}
