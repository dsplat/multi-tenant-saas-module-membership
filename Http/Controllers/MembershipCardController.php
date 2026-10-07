<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Membership\Models\MembershipCard;

/**
 * 会员卡控制器（Console 运营面）
 *
 * discount_rate / points_multiplier 为比率列，入参用 numeric（非金额，不走整数最小单位）。
 */
class MembershipCardController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $perPage = (int) $request->input('per_page', 20);

        $query = MembershipCard::where('tenant_id', $tenantId);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $paginator = $query->orderByDesc('created_at')
            ->orderByDesc('card_id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    public function show($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $card = MembershipCard::where('tenant_id', $tenantId)
            ->where('card_id', $id)
            ->first();

        if (! $card) {
            return response()->json(['success' => false, 'message' => '会员卡不存在'], 404);
        }

        return response()->json(['success' => true, 'data' => $card]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'level' => 'nullable|string|max:50',
            'discount_rate' => 'nullable|numeric|min:0|max:1',
            'points_multiplier' => 'nullable|numeric|min:0|max:10',
            'benefits' => 'nullable|array',
            'cover_image' => 'nullable|string|max:500',
            'description' => 'nullable|string|max:500',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $tenantId = (int) TenantContext::getId();

        $card = MembershipCard::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'level' => $validated['level'] ?? 'normal',
            'discount_rate' => $validated['discount_rate'] ?? 1.00,
            'points_multiplier' => $validated['points_multiplier'] ?? 1.0,
            'benefits' => $validated['benefits'] ?? [],
            'cover_image' => $validated['cover_image'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json(['success' => true, 'data' => $card], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'level' => 'sometimes|string|max:50',
            'discount_rate' => 'sometimes|numeric|min:0|max:1',
            'points_multiplier' => 'sometimes|numeric|min:0|max:10',
            'benefits' => 'sometimes|array',
            'cover_image' => 'sometimes|string|max:500',
            'description' => 'nullable|string|max:500',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $tenantId = (int) TenantContext::getId();

        $card = MembershipCard::where('tenant_id', $tenantId)
            ->where('card_id', $id)
            ->first();

        if (! $card) {
            return response()->json(['success' => false, 'message' => '会员卡不存在'], 404);
        }

        $card->update($validated);

        return response()->json(['success' => true, 'data' => $card->fresh()]);
    }

    public function destroy($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $card = MembershipCard::where('tenant_id', $tenantId)
            ->where('card_id', $id)
            ->first();

        if (! $card) {
            return response()->json(['success' => false, 'message' => '会员卡不存在'], 404);
        }

        $card->delete();

        return response()->json(['success' => true, 'message' => '会员卡已删除']);
    }

    public function uploadCover(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|max:2048',
        ]);

        $path = $request->file('file')->store('membership-covers', 'public');

        return response()->json([
            'success' => true,
            'data' => ['url' => '/storage/' . $path],
        ]);
    }
}
