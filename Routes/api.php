<?php

use Illuminate\Support\Facades\Route;

use MultiTenantSaas\Modules\Membership\Http\Controllers\MemberPointsController;
use MultiTenantSaas\Modules\Membership\Http\Controllers\MembershipCardController;
use MultiTenantSaas\Modules\Membership\Http\Controllers\MembershipController;
use MultiTenantSaas\Modules\Membership\Http\Controllers\PointsController;

// ========== 会员体系模块 ==========
//
// 路由前缀由 MembershipServiceProvider::loadModuleRoutes() 从 config('membership.route_prefix')
// 读取（框架默认 api/v1；下游设 MEMBERSHIP_ROUTE_PREFIX=api/v1/biz 沿用既有契约）。
// 用户身份一律走框架 user_id（路由参数 {userId}），不引用 Customer。
//
// **未注册**：积分商城（points/products、points/exchange）—— points_products + PointsExchangeService
// 属 SCRM 特有计划，留在项目层。

// ---------- C 端（终端用户 H5）----------
// self-scoped：仅操作「当前登录用户自身」的积分，不接受 userId 入参（见 MemberPointsController）。
// 非运营面，故不挂 rbac.permission（RBAC 只作用于 Operator，且不得拦截 User 自读自身数据）。
Route::prefix('member')->group(function () {
    Route::get('points/balance', [MemberPointsController::class, 'balance']);
    Route::get('points/flow', [MemberPointsController::class, 'flow']);
});

// ---------- B 端（租户运营 Console）----------
// 运营面，须挂 rbac.permission（BL-062）：只读 → membership.view；写/变更 → membership.manage。

Route::middleware('rbac.permission:membership.view')->group(function () {
    // 会员等级
    Route::get('membership/distribution', [MembershipController::class, 'distribution']);
    Route::get('membership-levels', [MembershipController::class, 'index']);
    Route::get('membership-levels/{id}/benefits', [MembershipController::class, 'benefits']);
    Route::get('membership-levels/{id}', [MembershipController::class, 'show'])->whereNumber('id');
    Route::get('users/{userId}/membership/evaluate', [MembershipController::class, 'evaluateUpgrade']);
    Route::get('users/{userId}/membership/logs', [MembershipController::class, 'changeLogs']);

    // 积分规则 / 余额 / 流水 / 日志
    Route::get('points/balance/{userId}', [PointsController::class, 'balance']);
    Route::get('points/flow/{userId}', [PointsController::class, 'flow']);
    Route::get('points-logs', [PointsController::class, 'pointsLogs']);
    Route::get('points-rules', [PointsController::class, 'index']);
    Route::get('points-rules/{id}', [PointsController::class, 'show'])->whereNumber('id');

    // 会员卡
    Route::get('membership-cards', [MembershipCardController::class, 'index']);
    Route::get('membership-cards/{id}', [MembershipCardController::class, 'show'])->whereNumber('id');
});

Route::middleware('rbac.permission:membership.manage')->group(function () {
    // 会员等级
    Route::post('membership-levels', [MembershipController::class, 'store']);
    Route::put('membership-levels/{id}/benefits', [MembershipController::class, 'updateBenefits']);
    Route::put('membership-levels/{id}', [MembershipController::class, 'update']);
    Route::delete('membership-levels/{id}', [MembershipController::class, 'destroy']);
    Route::post('users/{userId}/membership/change', [MembershipController::class, 'changeLevel']);

    // 积分规则 / 调整 / 过期
    Route::post('points/adjust/{userId}', [PointsController::class, 'adjust']);
    Route::post('points/expire', [PointsController::class, 'expire']);
    Route::post('points-rules', [PointsController::class, 'store']);
    Route::patch('points-rules/{id}/toggle', [PointsController::class, 'toggleRule']);
    Route::put('points-rules/{id}', [PointsController::class, 'update']);
    Route::delete('points-rules/{id}', [PointsController::class, 'destroy']);

    // 会员卡
    Route::post('membership-cards/upload-cover', [MembershipCardController::class, 'uploadCover']);
    Route::post('membership-cards', [MembershipCardController::class, 'store']);
    Route::put('membership-cards/{id}', [MembershipCardController::class, 'update']);
    Route::delete('membership-cards/{id}', [MembershipCardController::class, 'destroy']);
});
