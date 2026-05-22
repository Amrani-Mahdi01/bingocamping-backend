<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each variant row is now a purchasable (color, size) combination.
 *
 * - `color_*` columns are NULL when the product has no color axis.
 * - `size_label` is NULL when the product has no size axis.
 * - At least one of the two has to be non-null (enforced by the controller,
 *   not the DB, so rows can still represent edge cases during editing).
 *
 * The previous axis/value layout was too generic to express
 * "color → its sizes" naturally on the admin form.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Wipe & recreate — no production data yet.
        Schema::dropIfExists('product_variants');
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Color axis (null → product has no color)
            $table->string('color_name_fr')->nullable();
            $table->string('color_name_ar')->nullable();
            $table->string('color_hex', 9)->nullable(); // "#RRGGBB" or "#RRGGBBAA"

            // Size axis (null → product has no size)
            $table->string('size_label', 32)->nullable();

            $table->string('sku_suffix', 32)->nullable();
            $table->integer('price_delta')->default(0);
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            // Lookup paths for the admin UI: by color, by size, ordered.
            $table->index(['product_id', 'color_name_fr']);
            $table->index(['product_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        // Restore the original schema so down() is reversible.
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('axis_fr');
            $table->string('axis_ar');
            $table->string('value_fr');
            $table->string('value_ar');
            $table->string('sku_suffix', 32)->nullable();
            $table->integer('price_delta')->default(0);
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'display_order']);
        });
    }
};
