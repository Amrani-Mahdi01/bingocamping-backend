<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second colour hex for two-tone ("mixed") variant colours — e.g. a
 * "Cumulus Cloud / Anthracite Grey" colourway. NULL keeps the existing
 * solid-colour behaviour; when both hexes are set the storefront renders
 * a diagonal-split swatch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('color_hex2', 9)->nullable()->after('color_hex'); // "#RRGGBB" or "#RRGGBBAA"
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('color_hex2');
        });
    }
};
