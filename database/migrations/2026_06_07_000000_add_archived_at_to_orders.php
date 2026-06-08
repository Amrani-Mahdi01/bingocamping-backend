<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft "archive" for orders. Stale orders (untouched 3 months) are moved here
 * instead of being deleted: archived_at is set, the admin list hides them by
 * default but they stay in the DB (and in the stats) and can be restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
