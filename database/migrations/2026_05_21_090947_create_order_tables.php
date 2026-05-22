<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders + the audit/history tables that drive the admin workflow.
     *
     * Order lines snapshot the product's name/SKU/image/price at the moment
     * the order was placed — so editing a product later doesn't rewrite
     * historical orders.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique(); // BIN-2026-00042
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete(); // null for guest checkouts

            // Customer snapshot (guest checkouts have no customers row)
            $table->string('customer_first_name');
            $table->string('customer_last_name');
            $table->string('customer_phone', 32);
            $table->string('customer_email')->nullable();

            // Shipping snapshot
            $table->string('wilaya_id', 2);
            $table->string('wilaya_name'); // FR/AR snapshot, locale of placement
            $table->string('commune');
            $table->string('address');
            $table->text('notes')->nullable();

            // Money (DZD integer)
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('shipping_fee');
            $table->unsignedBigInteger('total');

            // State machine
            $table->string('status', 32)->default('pending'); // pending|confirmed|preparing|shipped|delivered|cancelled|returned
            $table->string('payment_method', 16)->default('cod');
            $table->string('payment_status', 16)->default('pending'); // pending|paid|refunded
            $table->string('tracking_number', 64)->nullable();
            $table->string('cancellation_reason', 255)->nullable();

            $table->timestamps();

            $table->foreign('wilaya_id')->references('id')->on('wilayas')->restrictOnDelete();
            $table->index('status');
            $table->index('wilaya_id');
            $table->index('created_at');
            $table->index('customer_phone');
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete(); // keep history if product is later removed

            // Snapshotted product data
            $table->string('product_name');
            $table->string('sku', 64);
            $table->string('image')->nullable();
            $table->string('variant')->nullable();

            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('total');

            $table->timestamps();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('by', 64)->nullable(); // admin email or "Système"
            $table->text('note')->nullable();
            $table->timestamp('at')->useCurrent();

            $table->index(['order_id', 'at']);
        });

        Schema::create('order_call_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('outcome', 16); // answered | no_answer | wrong_number | declined
            $table->string('by', 64)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('at')->useCurrent();

            $table->index(['order_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_call_attempts');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
