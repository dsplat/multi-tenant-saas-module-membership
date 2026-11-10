<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 会员等级有效期。
 *
 * - membership_levels.validity_days：0=永久，>0=该等级一个有效周期
 * - member_accounts.level_status：none|active|expired
 * - level_started_at / level_expires_at：当前会员周期
 *
 * 存量账户若有 current_level_id，按 active 回填但 expires_at 留空（不追溯到期）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('membership_levels') && ! Schema::hasColumn('membership_levels', 'validity_days')) {
            Schema::table('membership_levels', function (Blueprint $table) {
                $table->integer('validity_days')->default(0)->comment('有效天数，0=永久');
            });
        }

        if (Schema::hasTable('member_accounts')) {
            if (! Schema::hasColumn('member_accounts', 'level_status')) {
                Schema::table('member_accounts', function (Blueprint $table) {
                    $table->string('level_status', 20)->default('none')->comment('none|active|expired');
                    $table->index('level_status', 'member_accounts_level_status_index');
                });
            }

            if (! Schema::hasColumn('member_accounts', 'level_started_at')) {
                Schema::table('member_accounts', function (Blueprint $table) {
                    $table->timestamp('level_started_at')->nullable();
                });
            }

            if (! Schema::hasColumn('member_accounts', 'level_expires_at')) {
                Schema::table('member_accounts', function (Blueprint $table) {
                    $table->timestamp('level_expires_at')->nullable();
                    $table->index('level_expires_at', 'member_accounts_level_expires_at_index');
                });
            }

            DB::table('member_accounts')
                ->whereNotNull('current_level_id')
                ->where('level_status', 'none')
                ->update(['level_status' => 'active']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('member_accounts')) {
            Schema::table('member_accounts', function (Blueprint $table) {
                $table->dropIndex('member_accounts_level_status_index');
                $table->dropIndex('member_accounts_level_expires_at_index');
                $table->dropColumn(['level_status', 'level_started_at', 'level_expires_at']);
            });
        }

        if (Schema::hasTable('membership_levels') && Schema::hasColumn('membership_levels', 'validity_days')) {
            Schema::table('membership_levels', function (Blueprint $table) {
                $table->dropColumn('validity_days');
            });
        }
    }
};
