<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox table for the storefront /contact form. The public endpoint
 * is reCAPTCHA-gated (see ContactController::store) so we don't pile
 * up bot submissions. `is_read` + `read_at` drive the unread badge on
 * the admin Messages page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 191);
            $table->string('subject', 191);
            $table->text('message');
            $table->string('ip', 45)->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
