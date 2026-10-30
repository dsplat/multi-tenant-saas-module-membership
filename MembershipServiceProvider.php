<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Authorization\CapabilityDefinition;
use MultiTenantSaas\Context\ExecutionIdentity;
use MultiTenantSaas\Contracts\AuthorizationCapabilityRegistryContract;
use MultiTenantSaas\Contracts\CapabilityRequirementResolverContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Contracts\WriteAdapterRegistryContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\Tool;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Membership\Services\Tools\AdjustPointsHandler;
use MultiTenantSaas\Modules\Membership\Services\Tools\EvaluateMembershipHandler;
use MultiTenantSaas\Modules\Membership\Services\Tools\GetPointsBalanceHandler;
use MultiTenantSaas\Modules\Membership\Services\Tools\ListMembershipLevelsHandler;
use MultiTenantSaas\Modules\Membership\Services\Write\MembershipAdjustPointsWriteAdapter;
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
        $this->registerAuthorizationCapabilities();
        $this->registerWriteAdapters();
        $this->registerTools();
    }

    /**
     * P1 工具标注 wave2：Membership 只读能力。
     *
     * operator requirement 映射到既有权限名 `membership.view`（会员体系读端点同口径）。
     *
     * B2 写能力（调整积分）映射到既有权限名 `membership.manage`，与
     * `POST points/adjust/{userId}` 路由及 Console 写面同口径（避免同一操作
     * 在 Web 与 AI 两条路径要求不同权限）。
     */
    private function registerAuthorizationCapabilities(): void
    {
        if (! $this->app->bound(AuthorizationCapabilityRegistryContract::class)) {
            return;
        }

        $registry = $this->app->make(AuthorizationCapabilityRegistryContract::class);
        $requirements = $this->app->bound(CapabilityRequirementResolverContract::class)
            ? $this->app->make(CapabilityRequirementResolverContract::class)
            : null;

        $capabilities = [
            'common.membership.levels' => 'membership.view',
            'common.membership.evaluate' => 'membership.view',
            'common.membership.points_balance' => 'membership.view',
            'common.membership.adjust_points' => 'membership.manage',
        ];

        foreach ($capabilities as $key => $permission) {
            $registry->register(new CapabilityDefinition(
                key: $key,
                subjectTypes: [ExecutionIdentity::TYPE_OPERATOR],
                targetScope: CapabilityDefinition::TARGET_TENANT,
                admissionPolicy: [ExecutionIdentity::TYPE_OPERATOR => ['member']],
            ));

            $requirements?->register($key, ExecutionIdentity::TYPE_OPERATOR, ['permission' => $permission]);
        }
    }

    /**
     * B2 写试点：调整积分走显式注册的写授权适配器（ADR v2.5 §8.7）。
     *
     * 未注册/未声明 adapter 的写工具一律 write_unsupported，禁止回退旧 handler。
     */
    private function registerWriteAdapters(): void
    {
        if (! $this->app->bound(WriteAdapterRegistryContract::class)) {
            return;
        }

        $registry = $this->app->make(WriteAdapterRegistryContract::class);

        if ($registry->get(MembershipAdjustPointsWriteAdapter::ADAPTER_KEY) !== null) {
            return;
        }

        $this->app->singleton(MembershipAdjustPointsWriteAdapter::class);

        $registry->register(
            MembershipAdjustPointsWriteAdapter::ADAPTER_KEY,
            $this->app->make(MembershipAdjustPointsWriteAdapter::class),
        );
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
            requiredCapability: 'common.membership.levels',
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
            requiredCapability: 'common.membership.evaluate',
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
            requiredCapability: 'common.membership.points_balance',
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
                    'operation_key' => ['type' => 'string', 'description' => '幂等键（可选；同一逻辑操作重试建议复用；1-32 位字母/数字/._:-）'],
                ],
                'required' => ['user_id', 'amount', 'reason'],
            ],
            category: 'membership',
            risk: 'L2',
            dataAction: Tool::DATA_ACTION_WRITE,
            requiredCapability: 'common.membership.adjust_points',
            writeAdapter: MembershipAdjustPointsWriteAdapter::ADAPTER_KEY,
        );
    }
}
