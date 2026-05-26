<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `block_group_id` links a blocked IP row to its matching blocked
 * phone row (and vice-versa) when both were created from the same
 * "block this client" event — either the manual customer block or
 * the 3-strike auto-block. The /admin/blocked-ips list groups by it
 * so paired entries render as a single row with a single "Débloquer"
 * action that removes both at once.
 *
 * Nullable: manually adding just an IP or just a phone leaves the
 * column null on that row, and the UI renders those as standalone
 * entries. Indexed because both list endpoints filter on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blocked_ips', function (Blueprint $table) {
            $table->string('block_group_id', 64)->nullable()->index()->after('reason');
        });
        Schema::table('blocked_phones', function (Blueprint $table) {
            $table->string('block_group_id', 64)->nullable()->index()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('blocked_ips', function (Blueprint $table) {
            $table->dropIndex(['block_group_id']);
            $table->dropColumn('block_group_id');
        });
        Schema::table('blocked_phones', function (Blueprint $table) {
            $table->dropIndex(['block_group_id']);
            $table->dropColumn('block_group_id');
        });
    }
};
