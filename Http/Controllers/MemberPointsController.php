<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Membership\Services\PointsRuleService;

/**
 * C 端会员积分控制器
 *
 * 面向终端用户（H5），通过认证用户的 user_id 直接定位自身积分数据，
 * 仅允许查询自己的积分，不暴露 userId 路径参数（self-scoped）。
 *
 * 物理归位说明：由 scrm 自建模块提升进框架，按身份铁律改走框架 User / user_id
 * （原实现引用 App\Modules\Customer\Models\Customer，已移除）。积分商城兑换
 * （points_products + PointsExchangeService）属 SCRM 特有计划，未迁入。
 */
class MemberPointsController extends BaseController
{
    public function __construct(
        protected PointsRuleService $service,
    ) {}

    /**
     * 我的积分余额
     */
    public function balance(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);

        if ($userId === null) {
            return response()->json([
                'success' => true,
                'data' => ['balance' => 0, 'linked' => false],
            ]);
        }

        $tenantId = (int) TenantContext::getId();
        $balance = $this->service->getPointsBalance($tenantId, $userId);

        return response()->json([
            'success' => true,
            'data' => [
                'balance' => $balance,
                'linked' => true,
            ],
        ]);
    }

    /**
     * 我的积分流水
     */
    public function flow(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);

        if ($userId === null) {
            return response()->json([
                'success' => true,
                'data' => ['items' => [], 'total' => 0],
            ]);
        }

        $tenantId = (int) TenantContext::getId();
        $type = $request->input('type');
        $flow = $this->service->getPointsFlow($tenantId, $userId, $type);

        return response()->json(['success' => true, 'data' => $flow]);
    }

    /**
     * 从认证上下文派生当前用户 ID（self-scoped，不接受入参 userId）
     */
    protected function currentUserId(Request $request): ?int
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return (int) $user->getKey();
    }
}
