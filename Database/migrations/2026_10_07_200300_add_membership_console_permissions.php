<?php

use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\IdGeneratorContract;

/**
 * Membership Console 面权限点（提升归位时补发，2026-10-07）。
 *
 * Membership 由 scrm 自建模块物理归位进框架后，其 Console 运营面路由（等级/积分规则/
 * 会员卡 CRUD）按守卫检查 24 须挂 rbac.permission。本迁移补发权限点：
 * - 只读面（GET：列表/详情/权益/评估/流水/日志）→ `membership.view`
 * - 写/管理面（POST/PUT/PATCH/DELETE：增删改、启停、调整、变更、上传封面）→ `membership.manage`
 *
 * 命名口径：本文件挂 console/租户面（api/v1 + tenant.identify），权限取**裸名**（不带 `platform.` 前缀）。
 * 本迁移负责**存量库升级**——RolePermissionSeeder 只在全新安装运行，故权限定义与授权必须在此幂等完成，
 * 使全新安装（PermissionSeeder + RolePermissionSeeder）与存量升级（本迁移）的授权结果一致。
 *
 * @see src/Modules/Course/Database/migrations/2026_10_06_200200_add_course_console_permissions.php
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'membership.view', 'display_name' => '查看会员体系', 'group' => 'membership'],
        ['name' => 'membership.manage', 'display_name' => '管理会员体系', 'group' => 'membership'],
    ];

    /** 全量型管理角色：获得全部权限 */
    private const MANAGE_ROLES = ['super_admin', 'platform_admin', 'tenant_admin'];

    /** 模式型只读角色：仅获得 *.view（镜像 RolePermissionSeeder 的 str_contains('.view')） */
    private const VIEW_ROLES = ['platform_support', 'member', 'viewer', 'analyst'];

    public function up(): void
    {
        // 模块迁移可能先于 RBAC 表存在（最小安装）——表不在就跳过，等 Seeder 覆盖
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $idGen = app(IdGeneratorContract::class);
        $now = now();

        foreach (self::PERMISSIONS as $perm) {
            // 幂等：name 唯一键，已存在则复用其 permission_id
            $permId = DB::table('permissions')->where('name', $perm['name'])->value('permission_id');

            if (! $permId) {
                $permId = $idGen->generate();

                DB::table('permissions')->insert([
                    'permission_id' => $permId,
                    'name' => $perm['name'],
                    'display_name' => $perm['display_name'],
                    'group' => $perm['group'],
                    'description' => $perm['display_name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $roles = str_ends_with($perm['name'], '.view')
                ? array_merge(self::MANAGE_ROLES, self::VIEW_ROLES)
                : self::MANAGE_ROLES;

            $this->grant((int) $permId, $roles, $now);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permIds = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->pluck('permission_id')
            ->all();

        if ($permIds === []) {
            return;
        }

        // 显式删映射，不依赖外键 ON DELETE CASCADE（测试环境 DB_FOREIGN_KEYS=false）
        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('permission_id', $permIds)->delete();
        }

        DB::table('permissions')->whereIn('permission_id', $permIds)->delete();
    }

    /**
     * 幂等授权给全局角色（tenant_id 为 NULL 的系统角色）。
     *
     * @param  array<int, string>  $roleNames
     */
    private function grant(int $permissionId, array $roleNames, CarbonInterface $now): void
    {
        $roleIds = DB::table('roles')
            ->whereIn('name', $roleNames)
            ->whereNull('tenant_id')
            ->pluck('role_id')
            ->all();

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => (int) $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
