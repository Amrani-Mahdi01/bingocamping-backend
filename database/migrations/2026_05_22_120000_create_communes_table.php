<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Communes belong to a wilaya. Algeria has ~1500 communes total —
     * we don't seed them all; admins add the ones they actually ship to.
     * Code is the 5-digit postal code (string to preserve leading zeros).
     */
    public function up(): void
    {
        Schema::create('communes', function (Blueprint $table) {
            $table->id();
            $table->string('wilaya_id', 2);
            $table->string('code', 16); // postal code or commune ref
            $table->string('name_fr');
            $table->string('name_ar');
            $table->timestamps();

            $table->foreign('wilaya_id')
                ->references('id')
                ->on('wilayas')
                ->cascadeOnDelete();
            $table->index('wilaya_id');
            $table->unique(['wilaya_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communes');
    }
};
