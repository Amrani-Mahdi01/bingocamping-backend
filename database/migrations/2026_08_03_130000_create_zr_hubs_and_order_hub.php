<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Persist ZR Express pickup points (stop desks) so checkout can let the
     * customer pick the EXACT desk, not just the commune. A wilaya can hold
     * several desks in the same commune (e.g. Constantine: Zouaghi, Belle Vue,
     * Nouvelle Ville) — the commune-only picker collapsed them to one. Rows are
     * rebuilt from ZR on each "Synchroniser les territoires". Orders remember
     * the chosen desk in `zr_hub_id` so the parcel goes exactly where the
     * customer chose (instead of the server auto-picking one).
     */
    public function up(): void
    {
        Schema::create('zr_hubs', function (Blueprint $table) {
            $table->string('id', 64)->primary();          // ZR hub UUID
            $table->string('wilaya_id', 2)->index();       // local wilaya id
            $table->string('name');                        // clean label, e.g. "Zouaghi"
            $table->string('commune_name')->nullable();    // matched commune (display + parcel)
            $table->string('address')->nullable();         // "street, city"
            $table->string('district_territory_id', 64)->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('zr_hub_id', 64)->nullable()->after('delivery_type');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('zr_hub_id');
        });
        Schema::dropIfExists('zr_hubs');
    }
};
