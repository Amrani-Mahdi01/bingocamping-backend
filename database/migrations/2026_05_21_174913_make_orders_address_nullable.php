<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Guest checkouts can ship to "wilaya + commune" without a
            // street address — the ZR Express driver phones the customer
            // to confirm the drop-off point.
            $table->string('address')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('address')->nullable(false)->change();
        });
    }
};
