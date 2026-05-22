<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalog backbone: wilayas, brands, categories (with self-referencing
     * parent_id for subcategories), products, variants, images, banners.
     *
     * Translatable text is stored as two columns per locale (`*_fr`, `*_ar`).
     * No translations table — keeps queries simple and admin forms map 1:1
     * to two fields per language.
     */
    public function up(): void
    {
        // 58 Algerian wilayas — slow-changing reference data
        Schema::create('wilayas', function (Blueprint $table) {
            $table->string('id', 2)->primary(); // "01" .. "58"
            $table->string('code', 2)->index();
            $table->string('name_fr');
            $table->string('name_ar');
            $table->string('region', 16); // Centre | Est | Ouest | Sud
            $table->unsignedInteger('shipping_price'); // DZD
            $table->unsignedTinyInteger('delivery_days');
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('logo')->nullable();
            $table->string('country', 2)->nullable();
            $table->text('description_fr')->nullable();
            $table->text('description_ar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_fr');
            $table->string('name_ar');
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();
            $table->string('icon', 64)->default('Folder'); // lucide-react name
            $table->string('image')->nullable();
            $table->text('description_fr')->nullable();
            $table->text('description_ar')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'display_order']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('sku', 64)->unique();
            $table->string('name_fr');
            $table->string('name_ar');
            $table->string('description_short_fr', 500)->nullable();
            $table->string('description_short_ar', 500)->nullable();
            $table->longText('description_fr')->nullable();
            $table->longText('description_ar')->nullable();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('price'); // DZD, integer (no cents)
            $table->unsignedBigInteger('old_price')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->boolean('track_stock')->default(true);
            $table->boolean('allow_backorder')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new')->default(false);
            $table->boolean('is_best_seller')->default(false);
            $table->boolean('is_promo')->default(false);
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('sold_count')->default(0);
            $table->json('attributes')->nullable(); // [{label_fr, label_ar, value}]
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['category_id', 'is_active']);
            $table->index(['brand_id', 'is_active']);
            $table->index('is_featured');
            $table->index('is_promo');
            $table->index('is_new');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('axis_fr');   // e.g. "Taille" / "Couleur"
            $table->string('axis_ar');
            $table->string('value_fr');  // e.g. "M" / "Olive"
            $table->string('value_ar');
            $table->string('sku_suffix', 32)->nullable();
            $table->integer('price_delta')->default(0); // can be negative
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'display_order']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('url'); // /storage/products/<file>.jpg
            $table->string('alt_fr')->nullable();
            $table->string('alt_ar')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'display_order']);
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title_fr')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('subtitle_fr')->nullable();
            $table->string('subtitle_ar')->nullable();
            $table->string('cta_label_fr')->nullable();
            $table->string('cta_label_ar')->nullable();
            $table->string('image');
            $table->string('link')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('wilayas');
    }
};
