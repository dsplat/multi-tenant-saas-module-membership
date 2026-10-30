<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 会员体系模块（Membership）建表（框架侧标准件）
 *
 * 物理归位自 scrm `database/migrations/2025_01_03_000014_membership_module.php`：
 * - 保留通用底座表：membership_levels / membership_cards / points_rules / points_transactions；
 * - **去掉项目特有的 points_products / points_exchanges**（积分商品兑换编排留在项目层）；
 * - `customer_membership_logs` 按身份铁律去 customer_ 前缀、改 user_id 口径，
 *   重命名为 `membership_logs`（主键 membership_log_id）。
 *
 * 幂等：每张表先 Schema::hasTable 再 Schema::create —— 框架全新安装建表；scrm 存量库
 * 这些表已存在则**跳过**（不重复建、不报错）。列型一律 Schema Builder（MySQL/sqlite 可移植），
 * 不写裸 MySQL DDL。金额铁律：本模块无交易金额列；discount_rate / points_multiplier 为
 * **比率列**（保持 decimal），points 为积分数（非金额），均不属于金额口径。
 *
 * 迁移名沿用 scrm 既有 basename，使 scrm 存量库（migrations 表已有同名记录）自动跳过本迁移，
 * hasTable 守卫则作为第二道保险。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('membership_levels')) {
            Schema::create('membership_levels', function (Blueprint $table) {
                $table->unsignedBigInteger('membership_level_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->string('name', 255);
                $table->string('icon', 255)->nullable();
                $table->integer('sort_order')->default(0);
                $table->json('upgrade_conditions')->nullable();
                $table->json('retain_conditions')->nullable();
                $table->json('benefits')->nullable();
                $table->string('status', 255)->default('active');
                $table->timestamps();
                $table->softDeletes();
                $table->index('tenant_id', 'membership_levels_tenant_id_index');
            });
        }

        if (! Schema::hasTable('membership_cards')) {
            Schema::create('membership_cards', function (Blueprint $table) {
                $table->unsignedBigInteger('card_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->string('name', 100);
                $table->string('level', 50)->default('normal');
                $table->decimal('discount_rate', 3, 2)->default(1.00)->comment('折扣率（比率列，非金额）');
                $table->decimal('points_multiplier', 3, 1)->default(1.0)->comment('积分倍率（比率列，非金额）');
                $table->json('benefits')->nullable();
                $table->string('cover_image', 500)->nullable();
                $table->string('description', 500)->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamps();
                $table->softDeletes();
                $table->index('tenant_id', 'membership_cards_tenant_id_index');
            });
        }

        if (! Schema::hasTable('points_rules')) {
            Schema::create('points_rules', function (Blueprint $table) {
                $table->unsignedBigInteger('points_rule_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->string('name', 255);
                $table->string('trigger_type', 255)->comment('purchase|sign_in|referral|manual|birthday');
                $table->integer('points')->default(0)->comment('积分数（非金额）');
                $table->json('conditions')->nullable();
                $table->integer('daily_limit')->default(0);
                $table->integer('total_limit')->default(0);
                $table->string('status', 255)->default('active');
                $table->timestamps();
                $table->softDeletes();
                $table->index('tenant_id', 'points_rules_tenant_id_index');
            });
        }

        if (! Schema::hasTable('points_transactions')) {
            Schema::create('points_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('points_transaction_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id')->comment('框架 User（user_id 口径）');
                $table->string('type', 255)->comment('earn|consume|adjust|expire');
                $table->integer('amount')->comment('积分数（卖出为负；非金额）');
                $table->integer('balance_after');
                $table->unsignedBigInteger('rule_id')->nullable();
                $table->string('related_type', 255)->nullable();
                $table->unsignedBigInteger('related_id')->nullable();
                $table->string('description', 255)->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('expired')->default(false);
                $table->timestamps();
                $table->index('tenant_id', 'points_transactions_tenant_id_index');
                $table->index('user_id', 'points_transactions_user_id_index');
            });
        }

        if (! Schema::hasTable('membership_logs')) {
            Schema::create('membership_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('membership_log_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id')->comment('框架 User（user_id 口径）');
                $table->unsignedBigInteger('from_level_id')->nullable();
                $table->unsignedBigInteger('to_level_id')->nullable();
                $table->string('change_type', 255)->default('upgrade')->comment('upgrade|downgrade|retain|init');
                $table->string('reason', 255)->nullable();
                $table->timestamps();
                $table->index('from_level_id', 'membership_logs_from_level_id_index');
                $table->index('to_level_id', 'membership_logs_to_level_id_index');
                $table->index('tenant_id', 'membership_logs_tenant_id_index');
                $table->index('user_id', 'membership_logs_user_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_logs');
        Schema::dropIfExists('points_transactions');
        Schema::dropIfExists('points_rules');
        Schema::dropIfExists('membership_cards');
        Schema::dropIfExists('membership_levels');
    }
};
