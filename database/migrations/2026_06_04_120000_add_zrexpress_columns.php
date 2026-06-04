<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * ZR Express delivery integration.
     *
     * Orders gain a link to the ZR parcel they were pushed to, plus a small
     * sync audit trail (last known ZR state, when we last polled, and the
     * last send error so the admin can see why a parcel failed to create).
     * `tracking_number` already exists on the orders table.
     *
     * Wilayas/communes gain the ZR "territory" UUID they map to — ZR's API
     * addresses destinations by UUID, not by the Algerian wilaya code, so we
     * cache the mapping after a one-off "sync territories" admin action.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // ZR's internal parcel UUID (returned by POST /parcels). Distinct
            // from tracking_number, which is the human-facing code shown to
            // the customer and fetched in a follow-up GET /parcels/{id}.
            $table->string('zr_parcel_id', 64)->nullable()->after('tracking_number');
            // Last ZR state slug we observed (e.g. "en_livraison", "livre").
            $table->string('zr_state', 64)->nullable()->after('zr_parcel_id');
            $table->timestamp('zr_synced_at')->nullable()->after('zr_state');
            // Surfaced to the admin when a parcel push fails so they can fix
            // the cause (missing territory mapping, bad token…) and retry.
            $table->string('zr_last_error', 500)->nullable()->after('zr_synced_at');

            $table->index('zr_parcel_id');
        });

        Schema::table('wilayas', function (Blueprint $table) {
            $table->string('zr_territory_id', 64)->nullable()->after('stop_desk_price');
        });

        Schema::table('communes', function (Blueprint $table) {
            $table->string('zr_district_id', 64)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['zr_parcel_id']);
            $table->dropColumn(['zr_parcel_id', 'zr_state', 'zr_synced_at', 'zr_last_error']);
        });
        Schema::table('wilayas', function (Blueprint $table) {
            $table->dropColumn('zr_territory_id');
        });
        Schema::table('communes', function (Blueprint $table) {
            $table->dropColumn('zr_district_id');
        });
    }
};
