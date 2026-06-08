<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link each order line to the exact purchasable variant (color/size) it was
 * for, so confirming the order decrements THAT variant's stock — not just the
 * product total. Nullable: simple products (no variants) keep variant_id null
 * and still move the product-level stock. nullOnDelete so deleting a variant
 * later doesn't wipe historical order lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->foreignId('variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variant_id');
        });
    }
};
