<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proposal items keep their own copy of the package data (label, size,
     * negotiated price, divisibility). That way a lead can record negotiated
     * prices without touching the shared product, and deleting a price tier
     * no longer breaks existing proposals.
     */
    public function up(): void
    {
        Schema::table('proposal_items', function (Blueprint $table) {
            $table->string('tier_label')->nullable()->after('price_tier_id');
            $table->decimal('package_amount', 12, 3)->nullable()->after('tier_label');
            $table->unsignedInteger('package_price_cents')->nullable()->after('package_amount');
            $table->boolean('is_divisible')->default(true)->after('package_price_cents');
            $table->decimal('divisible_step', 8, 3)->nullable()->after('is_divisible');
            $table->unsignedInteger('min_order_packages')->default(1)->after('divisible_step');
        });

        Schema::table('proposal_items', function (Blueprint $table) {
            $table->dropForeign(['price_tier_id']);
        });

        Schema::table('proposal_items', function (Blueprint $table) {
            $table->unsignedBigInteger('price_tier_id')->nullable()->change();
            $table->foreign('price_tier_id')->references('id')->on('price_tiers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proposal_items', function (Blueprint $table) {
            $table->dropForeign(['price_tier_id']);
        });

        Schema::table('proposal_items', function (Blueprint $table) {
            $table->unsignedBigInteger('price_tier_id')->nullable(false)->change();
            $table->foreign('price_tier_id')->references('id')->on('price_tiers');
            $table->dropColumn([
                'tier_label',
                'package_amount',
                'package_price_cents',
                'is_divisible',
                'divisible_step',
                'min_order_packages',
            ]);
        });
    }
};
