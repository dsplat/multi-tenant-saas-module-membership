<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Membership\Models\MemberAchievement;
use MultiTenantSaas\Modules\Membership\Services\AchievementService;

/**
 * 数字成就控制器（Console 运营面 / C 端只读由 MemberProfileController 提供）。
 */
class AchievementController extends BaseController
{
    public function __construct(
        protected AchievementService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|string|in:active,inactive',
            'name' => 'nullable|string|max:100',
        ]);

        $tenantId = (int) TenantContext::getId();
        $result = $this->service->listDefinitions(
            $tenantId,
            $validated,
            (int) ($validated['per_page'] ?? 20),
        );

        return response()->json(['success' => true, ...$result]);
    }

    public function show($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data' => $this->service->getDefinition($tenantId, (int) $id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(true));
        $tenantId = (int) TenantContext::getId();
        $achievement = $this->service->createDefinition($tenantId, $validated);

        return response()->json(['success' => true, 'data' => $achievement], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $validated = $request->validate($this->rules(false));
        $tenantId = (int) TenantContext::getId();
        $achievement = $this->service->updateDefinition($tenantId, (int) $id, $validated);

        return response()->json(['success' => true, 'data' => $achievement]);
    }

    public function destroy($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $this->service->deleteDefinition($tenantId, (int) $id);

        return response()->json(['success' => true, 'message' => '成就已删除']);
    }

    public function awards(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'achievement_id' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $tenantId = (int) TenantContext::getId();
        $result = $this->service->listAwards(
            $tenantId,
            isset($validated['achievement_id']) ? (int) $validated['achievement_id'] : null,
            (int) ($validated['per_page'] ?? 20),
        );

        return response()->json(['success' => true, ...$result]);
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $conditionOptions = implode(',', MemberAchievement::CONDITIONS);
        $rewardOptions = implode(',', MemberAchievement::REWARDS);

        return [
            'code' => $required.'|string|max:100|alpha_dash',
            'name' => $required.'|string|max:100',
            'description' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:500',
            'condition_type' => $required.'|string|in:'.$conditionOptions,
            'condition_value' => $required.'|integer|min:1',
            'reward_type' => 'nullable|string|in:'.$rewardOptions,
            'points_reward' => 'nullable|integer|min:0',
            'reward_config' => 'nullable|array',
            'status' => 'nullable|string|in:active,inactive',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }
}
