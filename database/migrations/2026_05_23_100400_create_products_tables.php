<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('visibility')->default('private');
            $table->string('unit', 16)->default('kg');
            $table->string('packaging_strategy', 32);
            $table->text('description')->nullable();
            $table->unsignedInteger('estimated_price_cents')->nullable();
            $table->decimal('estimated_price_per_unit', 10, 4)->nullable();
            $table->timestamps();

            $table->unique(['manufacturer_id', 'slug']);
            $table->index(['visibility', 'group_id']);
        });

        Schema::create('price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->decimal('package_amount', 12, 3);
            $table->unsignedInteger('min_order_packages')->default(1);
            $table->unsignedInteger('price_cents');
            $table->boolean('is_divisible')->default(true);
            $table->decimal('divisible_step', 8, 3)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('price_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_tier_id')->nullable()->constrained('price_tiers')->nullOnDelete();
            $table->foreignId('round_id')->nullable();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('observed_price_cents');
            $table->decimal('package_amount', 12, 3);
            $table->date('observed_on');
            $table->timestamps();

            $table->index(['product_id', 'observed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_observations');
        Schema::dropIfExists('price_tiers');
        Schema::dropIfExists('products');
    }
};
