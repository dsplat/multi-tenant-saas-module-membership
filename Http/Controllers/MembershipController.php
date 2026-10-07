<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Membership\Services\MembershipService;

/**
 * 会员等级控制器（Console 运营面）
 *
 * 等级 CRUD / 权益 / 升级评估 / 等级变更 / 分布统计。
 * 用户身份一律走框架 user_id（路由参数 {userId}），不再引用 Customer 实体。
 */
class MembershipController extends BaseController
{
    public function __construct(
        protected MembershipService $membershipService,
    ) {}

    /**
     * 等级列表
     */
    public function index(): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $levels = $this->membershipService->getLevels($tenantId);

        return response()->json(['success' => true, 'data' => $levels]);
    }

    /**
     * 创建等级
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'icon' => 'nullable|string|max:500',
            'sort_order' => 'integer|min:0',
            'upgrade_conditions' => 'nullable|array',
            'retain_conditions' => 'nullable|array',
            'benefits' => 'nullable|array',
        ]);

        $tenantId = (int) TenantContext::getId();
        $level = $this->membershipService->create($tenantId, $validated);

        return response()->json(['success' => true, 'data' => $level], 201);
    }

    /**
     * 等级详情
     */
    public function show($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $level = $this->membershipService->getLevel($tenantId, $id);

        return response()->json(['success' => true, 'data' => $level]);
    }

    /**
     * 更新等级
     */
    public function update(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'icon' => 'nullable|string|max:500',
            'sort_order' => 'integer|min:0',
            'upgrade_conditions' => 'nullable|array',
            'retain_conditions' => 'nullable|array',
            'benefits' => 'nullable|array',
            'status' => 'string|in:active,inactive',
        ]);

        $tenantId = (int) TenantContext::getId();
        $level = $this->membershipService->update($tenantId, $id, $validated);

        return response()->json(['success' => true, 'data' => $level]);
    }

    /**
     * 删除等级
     */
    public function destroy($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $this->membershipService->delete($tenantId, $id);

        return response()->json(['success' => true, 'message' => '等级已删除']);
    }

    /**
     * 更新等级权益
     */
    public function updateBenefits(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'benefits' => 'required|array',
        ]);

        $tenantId = (int) TenantContext::getId();
        $level = $this->membershipService->updateBenefits($tenantId, $id, $validated['benefits']);

        return response()->json(['success' => true, 'data' => $level]);
    }

    /**
     * 获取等级权益
     */
    public function benefits($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $result = $this->membershipService->getBenefits($tenantId, $id);

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * 评估用户升级
     */
    public function evaluateUpgrade($userId): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $level = $this->membershipService->evaluateUpgrade($tenantId, $userId);

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $userId,
                'eligible_level' => $level ? $level->toArray() : null,
                'can_upgrade' => $level !== null,
            ],
        ]);
    }

    /**
     * 执行等级变更
     */
    public function changeLevel(Request $request, $userId): JsonResponse
    {
        $validated = $request->validate([
            'to_level_id' => 'required|integer',
            'change_type' => 'string|in:upgrade,downgrade,retain,init',
            'reason' => 'nullable|string|max:500',
        ]);

        $tenantId = (int) TenantContext::getId();
        $result = $this->membershipService->changeLevel(
            $tenantId,
            $userId,
            $validated['to_level_id'],
            $validated['change_type'] ?? 'upgrade',
            $validated['reason'] ?? null,
        );

        return response()->json(['success' => true, 'data' => $result], 201);
    }

    /**
     * 等级变更日志
     */
    public function changeLogs($userId): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $logs = $this->membershipService->getChangeLogs($tenantId, $userId);

        return response()->json(['success' => true, 'data' => $logs]);
    }

    /**
     * 分布统计
     */
    public function distribution(): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $result = $this->membershipService->getDistribution($tenantId);

        return response()->json(['success' => true, 'data' => $result]);
    }
}
