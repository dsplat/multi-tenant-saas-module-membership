<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Membership\Services\PointsRuleService;

/**
 * 积分规则控制器（Console 运营面）
 *
 * 规则 CRUD / 启停 / 余额 / 流水 / 手动调整 / 过期处理。
 * **未迁入**：积分商城商品列表与兑换（points_products + PointsExchangeService）——
 * 属 SCRM 特有计划，留在项目层。
 */
class PointsController extends BaseController
{
    public function __construct(
        protected PointsRuleService $service,
    ) {}

    /**
     * 积分规则列表
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $tenantId = (int) TenantContext::getId();
        $rules = $this->service->getRules($tenantId, $validated['per_page'] ?? 20);

        return response()->json(['success' => true, 'data' => $rules]);
    }

    /**
     * 创建积分规则
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'trigger_type' => 'required|string|in:purchase,sign_in,referral,manual,birthday',
            'points' => 'required|integer|min:1',
            'conditions' => 'nullable|array',
            'daily_limit' => 'nullable|integer|min:0',
            'total_limit' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $tenantId = (int) TenantContext::getId();
        $rule = $this->service->createRule($tenantId, $validated);

        return response()->json(['success' => true, 'data' => $rule], 201);
    }

    /**
     * 积分规则详情
     */
    public function show($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $rules = $this->service->getRules($tenantId);
        $rule = collect($rules['data'])->firstWhere('points_rule_id', $id);

        if (! $rule) {
            return response()->json(['success' => false, 'message' => 'Rule not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $rule]);
    }

    /**
     * 更新积分规则
     */
    public function update(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'trigger_type' => 'sometimes|string|in:purchase,sign_in,referral,manual,birthday',
            'points' => 'sometimes|integer|min:1',
            'conditions' => 'nullable|array',
            'daily_limit' => 'nullable|integer|min:0',
            'total_limit' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $tenantId = (int) TenantContext::getId();
        $rule = $this->service->updateRule($tenantId, $id, $validated);

        return response()->json(['success' => true, 'data' => $rule]);
    }

    /**
     * 删除积分规则
     */
    public function destroy($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $rules = $this->service->getRules($tenantId);
        $rule = collect($rules['data'])->firstWhere('points_rule_id', $id);

        if (! $rule) {
            return response()->json(['success' => false, 'message' => 'Rule not found'], 404);
        }

        $rule->delete();

        return response()->json(['success' => true]);
    }

    /**
     * 查询用户积分余额
     */
    public function balance($userId): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $balance = $this->service->getPointsBalance($tenantId, $userId);

        return response()->json(['success' => true, 'data' => ['user_id' => $userId, 'balance' => $balance]]);
    }

    /**
     * 查询用户积分流水
     */
    public function flow(Request $request, $userId): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $type = $request->input('type');
        $flow = $this->service->getPointsFlow($tenantId, $userId, $type);

        return response()->json(['success' => true, 'data' => $flow]);
    }

    /**
     * 手动调整积分
     */
    public function adjust(Request $request, $userId): JsonResponse
    {
        $validated = $request->validate([
            'points' => 'required|integer',
            'description' => 'required|string|max:255',
        ]);

        $tenantId = (int) TenantContext::getId();
        $result = $this->service->adjustPoints($tenantId, $userId, $validated['points'], $validated['description']);

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * 积分过期处理
     */
    public function expire(): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $expiredCount = $this->service->expirePoints($tenantId);

        return response()->json(['success' => true, 'data' => ['expired_count' => $expiredCount]]);
    }

    /**
     * 积分规则启停
     */
    public function toggleRule(Request $request, $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $rule = $this->service->toggleRule($tenantId, $id);

        return response()->json(['success' => true, 'data' => $rule]);
    }

    /**
     * 积分日志
     */
    public function pointsLogs(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $userId = $request->input('user_id');
        $type = $request->input('type');
        $perPage = (int) $request->input('per_page', 20);

        $logs = $this->service->getPointsLogs($tenantId, $userId, $type, $perPage);

        return response()->json(['success' => true, ...$logs]);
    }
}
