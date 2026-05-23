<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('preferred_price_tier_id')->nullable()->constrained('price_tiers')->nullOnDelete();
            $table->string('quantity_mode', 16)->default('exact');
            $table->decimal('exact_quantity', 12, 3)->nullable();
            $table->decimal('min_quantity', 12, 3)->nullable();
            $table->decimal('max_quantity', 12, 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['round_id', 'user_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
