<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Logged-in customer carts. One row per (customer, product, variant)
     * combination — the same product in two different sizes is two
     * rows. Anonymous visitors still use localStorage on the client;
     * this table mirrors that state to the backend the moment they
     * authenticate so their cart follows them across devices.
     */
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Nullable — products without color/size axes have no variant.
            $table->foreignId('variant_id')->nullable()
                ->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();

            // Same product + same variant for the same customer collapses
            // into a single row (the controller upserts by these keys).
            $table->unique(['customer_id', 'product_id', 'variant_id'], 'cart_items_customer_product_variant_unique');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
