<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposed_by_user_id')->constrained('users');
            $table->string('title');
            $table->string('status', 16)->default('draft');
            $table->text('description')->nullable();
            $table->unsignedInteger('shipping_cents')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['round_id', 'status']);
        });

        Schema::create('proposal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('order_proposals')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('price_tier_id')->constrained('price_tiers');
            $table->unsignedSmallInteger('packages_ordered');
            $table->unsignedInteger('total_price_cents');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('proposal_id');
        });

        Schema::create('proposal_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->decimal('quantity', 12, 3);
            $table->unsignedInteger('share_cents');
            $table->timestamps();

            $table->unique(['proposal_item_id', 'user_id']);
        });

        Schema::create('proposal_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('value', 8); // up | down
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['proposal_item_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_votes');
        Schema::dropIfExists('proposal_allocations');
        Schema::dropIfExists('proposal_items');
        Schema::dropIfExists('order_proposals');
    }
};
