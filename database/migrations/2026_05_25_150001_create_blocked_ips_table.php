<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blocklist of IP addresses that are forbidden from placing orders.
 * Populated manually by an admin via /admin/customers or
 * /admin/blocked-ips, or automatically by the future "N fake orders
 * → auto-block" rule.
 *
 * `ip_address` is unique so a block is idempotent: the same IP can
 * only appear once. `blocked_by_admin_id` is nullable so the system
 * (auto-block) can insert rows without an admin ID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->string('reason', 255)->nullable();
            $table->foreignId('blocked_by_admin_id')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_ips');
    }
};
