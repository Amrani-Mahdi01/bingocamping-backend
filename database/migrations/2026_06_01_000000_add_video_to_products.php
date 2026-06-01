<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One optional product video. Stored as a path/URL under
     * storage/app/public/products/videos/. Max one per product, so a
     * single nullable column is enough (no separate table needed).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('video')->nullable()->after('description_ar');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('video');
        });
    }
};
