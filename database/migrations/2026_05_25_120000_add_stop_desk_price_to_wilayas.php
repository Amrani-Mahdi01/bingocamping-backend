<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Adds a second per-wilaya price for stop-desk (point-relais)
     * delivery. The existing `shipping_price` column keeps representing
     * the home-delivery rate; this new column lets the merchant set the
     * point-relais rate independently instead of deriving it on the
     * frontend.
     */
    public function up(): void
    {
        Schema::table('wilayas', function (Blueprint $table) {
            // Default 0 just so existing rows don't violate NOT NULL on
            // SQLite; the UPDATE below backfills sensible values.
            $table->unsignedInteger('stop_desk_price')->default(0)->after('shipping_price');
        });

        // Backfill: stop-desk is typically 200–400 DZD cheaper than home
        // delivery (Yalidine / Maystro pattern). Use max(200, home - 300)
        // to give a sensible default per existing wilaya.
        DB::statement(<<<'SQL'
            UPDATE wilayas
            SET stop_desk_price = CASE
                WHEN shipping_price - 300 < 200 THEN 200
                ELSE shipping_price - 300
            END
        SQL);
    }

    public function down(): void
    {
        Schema::table('wilayas', function (Blueprint $table) {
            $table->dropColumn('stop_desk_price');
        });
    }
};
