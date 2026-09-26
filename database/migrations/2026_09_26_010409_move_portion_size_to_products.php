<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Package sizes of a product can be combined in one order, so how a
     * product is shared between people belongs to the product: in portions
     * of a fixed size, or in whole packages. The packaging strategy only
     * labelled products and the estimated price duplicated the package
     * prices — both go. Packages get the supplier's article number.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('portion_size', 8, 3)->nullable()->after('unit');
        });

        Schema::table('price_tiers', function (Blueprint $table) {
            $table->string('article_number', 64)->nullable()->after('label');
        });

        DB::table('products')->orderBy('id')->select('id')->each(function (object $product): void {
            $steps = DB::table('price_tiers')
                ->where('product_id', $product->id)
                ->where('is_divisible', true)
                ->whereNotNull('divisible_step')
                ->pluck('divisible_step')
                ->map(fn (mixed $step): float => (float) $step)
                ->filter(fn (float $step): bool => $step > 0);

            DB::table('products')->where('id', $product->id)->update([
                'portion_size' => $steps->isEmpty() ? null : $steps->min(),
            ]);
        });

        Schema::table('price_tiers', function (Blueprint $table) {
            $table->dropColumn(['is_divisible', 'divisible_step']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['packaging_strategy', 'estimated_price_cents', 'estimated_price_per_unit']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('packaging_strategy', 32)->default('tiered');
            $table->unsignedInteger('estimated_price_cents')->nullable();
            $table->decimal('estimated_price_per_unit', 10, 4)->nullable();
        });

        Schema::table('price_tiers', function (Blueprint $table) {
            $table->boolean('is_divisible')->default(true);
            $table->decimal('divisible_step', 8, 3)->nullable();
        });

        DB::table('products')->orderBy('id')->select(['id', 'portion_size'])->each(function (object $product): void {
            DB::table('price_tiers')->where('product_id', $product->id)->update([
                'is_divisible' => $product->portion_size !== null,
                'divisible_step' => $product->portion_size,
            ]);
        });

        Schema::table('price_tiers', function (Blueprint $table) {
            $table->dropColumn('article_number');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('portion_size');
        });
    }
};
