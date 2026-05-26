<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the IP address that placed each order. Used by the admin
 * "block this customer's IP" action and the future auto-block-after-
 * N-fake-orders rule. 45 chars covers both IPv4 and IPv6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_ip', 45)->nullable()->after('customer_email');
            $table->index('customer_ip');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['customer_ip']);
            $table->dropColumn('customer_ip');
        });
    }
};
