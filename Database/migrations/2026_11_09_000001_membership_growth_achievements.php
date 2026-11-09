<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 会员成长账户与数字成就引擎。
 *
 * - member_accounts：用户成长值 / 当前等级投影
 * - member_growth_logs：成长流水（tenant_id + idempotency_key 唯一，事件可重放）
 * - member_achievements：成就定义
 * - member_achievement_progress：用户成就进度
 * - member_achievement_awards：成就发奖记录（tenant_id + idempotency_key 唯一）
 *
 * 金额口径：成就的 consume_amount 条件值仍为整数分（与 orders.total_amount 同口径）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('member_accounts')) {
            Schema::create('member_accounts', function (Blueprint $table) {
                $table->unsignedBigInteger('member_account_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id')->comment('框架 User（user_id 口径）');
                $table->bigInteger('growth_value')->default(0)->comment('成长值（只增不减）');
                $table->unsignedBigInteger('current_level_id')->nullable()->comment('当前会员等级投影');
                $table->timestamp('last_signal_at')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'user_id'], 'member_accounts_tenant_user_unique');
                $table->index('current_level_id', 'member_accounts_current_level_id_index');
            });
        }

        if (! Schema::hasTable('member_growth_logs')) {
            Schema::create('member_growth_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('member_growth_log_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('source_type', 50);
                $table->string('source_id', 100);
                $table->bigInteger('change_amount')->comment('成长值变化（当前策略只增）');
                $table->bigInteger('balance_after');
                $table->string('idempotency_key', 191);
                $table->json('context')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'idempotency_key'], 'member_growth_logs_idempotency_unique');
                $table->index(['tenant_id', 'user_id', 'created_at'], 'member_growth_logs_user_created_index');
            });
        }

        if (! Schema::hasTable('member_achievements')) {
            Schema::create('member_achievements', function (Blueprint $table) {
                $table->unsignedBigInteger('member_achievement_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->string('code', 100)->comment('租户内稳定编码，供导入/事件映射');
                $table->string('name', 100);
                $table->text('description')->nullable();
                $table->string('icon', 500)->nullable();
                $table->string('condition_type', 50);
                $table->bigInteger('condition_value')->comment('目标值；金额类条件为整数分');
                $table->string('reward_type', 30)->default('points')->comment('points|certificate|points_and_certificate|none');
                $table->integer('points_reward')->default(0)->comment('积分奖励（非金额）');
                $table->json('reward_config')->nullable();
                $table->string('status', 20)->default('active');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['tenant_id', 'code'], 'member_achievements_tenant_code_unique');
                $table->index(['tenant_id', 'condition_type', 'status'], 'member_achievements_condition_status_index');
            });
        }

        if (! Schema::hasTable('member_achievement_progress')) {
            Schema::create('member_achievement_progress', function (Blueprint $table) {
                $table->unsignedBigInteger('member_achievement_progress_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('member_achievement_id');
                $table->bigInteger('progress_value')->default(0);
                $table->bigInteger('target_value');
                $table->string('status', 20)->default('pending')->comment('pending|completed');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(
                    ['tenant_id', 'user_id', 'member_achievement_id'],
                    'member_achievement_progress_user_unique'
                );
                $table->index(['tenant_id', 'user_id', 'status'], 'member_achievement_progress_user_status_index');
            });
        }

        if (! Schema::hasTable('member_achievement_awards')) {
            Schema::create('member_achievement_awards', function (Blueprint $table) {
                $table->unsignedBigInteger('member_achievement_award_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('member_achievement_id');
                $table->string('reward_type', 30);
                $table->bigInteger('reward_value')->default(0);
                $table->string('idempotency_key', 191);
                $table->json('context')->nullable();
                $table->timestamp('awarded_at');
                $table->timestamps();
                $table->unique(['tenant_id', 'idempotency_key'], 'member_achievement_awards_idempotency_unique');
                $table->index(['tenant_id', 'user_id', 'awarded_at'], 'member_achievement_awards_user_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_achievement_awards');
        Schema::dropIfExists('member_achievement_progress');
        Schema::dropIfExists('member_achievements');
        Schema::dropIfExists('member_growth_logs');
        Schema::dropIfExists('member_accounts');
    }
};
