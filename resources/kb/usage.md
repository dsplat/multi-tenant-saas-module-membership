# Membership 模块（会员体系）

会员等级 / 积分规则 / 会员卡（通用会员底座）：`membership_levels` + `membership_cards` +
`points_rules` + `points_transactions`（积分流水）+ `membership_logs`（等级变更日志）。

> 物理归位（2026-10-07）：本模块由 scrm 自建模块提升进框架（首个「框架无对应物」的
> 提升样板）。**通用底座在框架**；积分商品兑换编排（`points_products` +
> `PointsExchangeService` + 履约/虚拟支付/供货钩子）属 SCRM 特有，**留在项目层**。
> 身份口径按铁律改走框架 `User` / `user_id`（不再有 `Customer` 实体）。

## 路由前缀插口（重要）

模块路由前缀从 `config('membership.route_prefix')` 读取（覆写见
`MembershipServiceProvider::loadModuleRoutes()`）：

| 场景 | 配置 | 实际 URL |
|------|------|----------|
| 框架默认 | `MEMBERSHIP_ROUTE_PREFIX=api/v1` | `/api/v1/membership-levels`、`/api/v1/points-rules` … |
| 下游沿用既有契约 | `MEMBERSHIP_ROUTE_PREFIX=api/v1/biz` | `/api/v1/biz/membership-levels` … |

这是「让下游沿用既有路由命名空间」的通用约定，本模块为首个物理归位模块、首个引入者。

## 能力面

- **等级**：`membership-levels` CRUD / 权益（`benefits`）/ 分布统计 / 升级评估 / 等级变更 + 变更日志。
- **积分**：`points-rules` CRUD / 启停；余额、流水、手动调整、过期处理。
- **会员卡**：`membership-cards` CRUD / 封面上传。
- **C 端（self-scoped）**：`member/points/balance`、`member/points/flow`（仅当前登录 User 自身积分）。
- **AI 工具**：`list_membership_levels` / `evaluate_membership` / `get_points_balance` / `adjust_points`。

## 皮肤点与接线契约

| 皮肤点 | 机器 | 覆盖方式 |
|------|------|------|
| 视图 | `resources/console/routes.ts`（`membership/levels`、`membership/points`、`membership/cards`） | 下游同路径同名覆盖 |
| AI 工具 | 模块私有 `register()` | 下游同名 `register` 特化接管 |

## 权限点（Console 运营面）

运营面路由挂 `rbac.permission`：只读 → `membership.view`；写/变更 → `membership.manage`
（权限定义与授权见 `Database/migrations/2026_10_07_200300_add_membership_console_permissions.php`）。

## 配置插口

- `config/membership.php` 的 `route_prefix`（见上）。
- 等级升级/保级条件、权益均为 JSON（`upgrade_conditions` / `retain_conditions` / `benefits`），免 schema 变更。
