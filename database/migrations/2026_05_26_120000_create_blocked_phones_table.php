<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone-number blocklist — sibling of blocked_ips.
 *
 * Reason: IP blocking alone is bypassed by VPN/proxy use; phone
 * numbers tend to stay the same across attempts. Storefront order
 * POST now checks BOTH lists.
 *
 * `phone_number` is unique so blocking is idempotent (same number
 * can only appear once). `blocked_by_admin_id` is nullable so the
 * auto-block rule (3+ cancelled/returned orders) can insert rows
 * without a human actor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_phones', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number', 32)->unique();
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
        Schema::dropIfExists('blocked_phones');
    }
};
