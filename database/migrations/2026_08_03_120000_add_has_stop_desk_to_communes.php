<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Marks communes that host a ZR Express pickup point (stop desk).
     *
     * ZR exposes its pickup points via POST /hubs/search — each carries the
     * commune it sits in (address.districtTerritoryId). The "Synchroniser les
     * territoires" action now flags the matching commune so the storefront can
     * offer stop-desk delivery ONLY where a desk actually exists, instead of
     * listing every commune (which then fails at ship time with "Aucun point
     * de retrait"). Defaults to false; the sync sets it true.
     */
    public function up(): void
    {
        Schema::table('communes', function (Blueprint $table) {
            $table->boolean('has_stop_desk')->default(false)->after('zr_district_id');
        });
    }

    public function down(): void
    {
        Schema::table('communes', function (Blueprint $table) {
            $table->dropColumn('has_stop_desk');
        });
    }
};
