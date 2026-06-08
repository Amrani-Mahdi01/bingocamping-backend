<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Record whether each order is home delivery ('home', à domicile) or
     * stop-desk pickup ('stopdesk', retrait en agence / point-relais).
     *
     * Before this, the delivery choice was only captured as a free-text note,
     * so the fee was always charged at the home rate and the parcel was always
     * sent to ZR Express as 'home'. Existing rows default to 'home' — exactly
     * how they were already priced and shipped, so the default is backward-safe.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_type', 16)->default('home')->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_type');
        });
    }
};
