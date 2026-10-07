<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Membership\Services\Tools\AdjustPointsHandler;
use MultiTenantSaas\Modules\Membership\Services\Tools\EvaluateMembershipHandler;
use MultiTenantSaas\Modules\Membership\Services\Tools\GetPointsBalanceHandler;
use MultiTenantSaas\Modules\Membership\Services\Tools\ListMembershipLevelsHandler;
use MultiTenantSaas\Support\OptionalModule;

/**
 * 会员体系模块（BL-068 提升 / 框架侧标准件）
 *
 * 会员等级 / 积分规则 / 会员卡（通用会员底座）。物理归位自 scrm 自建模块：
 * 换仓库位 + vendor 名，不重写业务；`Customer` 口径改为框架 `User`（身份铁律），
 * 积分商品兑换编排（points_products + PointsExchangeService）留在项目层不迁。
 *
 * 形态照抄 Course/Product：继承框架模块基类 ModuleServiceProvider，只写 AI 工具注册，
 * 路由/迁移/配置交给基类。**唯一覆写**为 loadModuleRoutes()——把基类写死的 api/v1
 * 前缀换成 config('membership.route_prefix')，作为下游沿用既有路由命名空间（scrm 冻结在
 * api/v1/biz）的配置插口。这是「下游沿用既有路由前缀」的通用约定，本模块为首个物理归位
 * 模块、首个引入者。
 */
class MembershipServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'membership';

    /**
     * Infrastructure 提供的路由中间件（FQCN 字符串常量，理由同基类：core 刻意不假设
     * module-infrastructure 已装，故不用 `use X::class` 把它隐式埋进路由定义）。
     */
    private const MW_VERIFY_OPERATOR_TENANT = 'MultiTenantSaas\Modules\Infrastructure\Http\Middleware\VerifyOperatorTenant';

    private const MW_OPTIONAL_SANCTUM_AUTH = 'MultiTenantSaas\Modules\Infrastructure\Http\Middleware\OptionalSanctumAuth';

    protected function bootModule(): void
    {
        $this->registerTools();
    }

    /**
     * 覆写基类路由加载：前缀从 config('membership.route_prefix', 'api/v1') 读取。
     *
     * 其余中间件口径（api + auth:sanctum + throttle:api + tenant.identify +
     * VerifyOperatorTenant + module.enabled 门控）与基类保持一致，仅把基类写死的
     * 'api/v1' 换成配置项——下游（如 scrm）设 MEMBERSHIP_ROUTE_PREFIX=api/v1/biz
     * 即可沿用其既有路由契约，无需改框架代码，也无需重写模块。
     */
    protected function loadModuleRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        // 与基类同口径：路由别名/中间件由 module-infrastructure 提供，缺它时尽早抛可捕获异常。
        OptionalModule::ensure(self::MW_VERIFY_OPERATOR_TENANT, '会员模块路由加载', 'Infrastructure');

        $moduleDir = $this->getModulePath();
        $moduleGate = 'module.enabled:' . $this->moduleGateName();
        $prefix = (string) config('membership.route_prefix', 'api/v1');

        $apiRoute = $moduleDir . '/Routes/api.php';
        if (file_exists($apiRoute)) {
            Route::middleware(['api', 'auth:sanctum', 'throttle:api', 'tenant.identify', self::MW_VERIFY_OPERATOR_TENANT, $moduleGate])
                ->prefix($prefix)
                ->group($apiRoute);
        }

        $publicRoute = $moduleDir . '/Routes/public.php';
        if (file_exists($publicRoute)) {
            Route::middleware(['api'])->prefix($prefix)->group($publicRoute);
        }

        $optionalRoute = $moduleDir . '/Routes/optional.php';
        if (file_exists($optionalRoute)) {
            Route::middleware(['api', 'throttle:api', 'tenant.identify', self::MW_OPTIONAL_SANCTUM_AUTH, $moduleGate])
                ->prefix($prefix)
                ->group($optionalRoute);
        }
    }

    /**
     * 注册会员管理 AI 工具（Console 系统小秘书代配置用）。
     *
     * Ai 模块未启用时静默跳过——工具注册表由 Ai 提供，缺失则不注册。
     */
    private function registerTools(): void
    {
        if (! $this->app->bound(ToolRegistryContract::class)) {
            return;
        }

        $registry = $this->app->make(ToolRegistryContract::class);

        $registry->register(
            slug: 'list_membership_levels',
            name: '会员等级列表',
            description: '获取会员等级列表',
            handlerClass: ListMembershipLevelsHandler::class,
            schema: ['type' => 'object', 'properties' => []],
            category: 'membership',
            risk: 'L1',
        );

        $registry->register(
            slug: 'evaluate_membership',
            name: '评估会员等级',
            description: '评估用户会员等级',
            handlerClass: EvaluateMembershipHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'user_id' => ['type' => 'integer', 'description' => '用户 ID'],
                ],
                'required' => ['user_id'],
            ],
            category: 'membership',
            risk: 'L1',
        );

        $registry->register(
            slug: 'get_points_balance',
            name: '积分余额',
            description: '查询用户积分余额',
            handlerClass: GetPointsBalanceHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'user_id' => ['type' => 'integer', 'description' => '用户 ID'],
                ],
                'required' => ['user_id'],
            ],
            category: 'membership',
            risk: 'L1',
        );

        $registry->register(
            slug: 'adjust_points',
            name: '调整积分',
            description: '手动调整用户积分',
            handlerClass: AdjustPointsHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'user_id' => ['type' => 'integer', 'description' => '用户 ID'],
                    'amount' => ['type' => 'integer', 'description' => '调整数量（正数增加，负数扣减）'],
                    'reason' => ['type' => 'string', 'description' => '调整原因'],
                ],
                'required' => ['user_id', 'amount', 'reason'],
            ],
            category: 'membership',
            risk: 'L2',
        );
    }
}
