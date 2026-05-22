<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-customer secondary data: shipping addresses + favorited products.
     */
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label', 32)->default('Domicile'); // Domicile / Bureau / …
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 32);
            $table->string('wilaya_id', 2);
            $table->string('commune');
            $table->string('address');
            $table->text('notes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('wilaya_id')->references('id')->on('wilayas')->restrictOnDelete();
            $table->index(['customer_id', 'is_default']);
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['customer_id', 'product_id']);
            $table->index('product_id'); // "who favourited this product"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('addresses');
    }
};
