<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Tables that belong to rounds, children first.
     *
     * @var array<int, string>
     */
    private const ROUND_TABLES = [
        'proposal_votes',
        'proposal_allocations',
        'proposal_items',
        'order_proposals',
        'cart_items',
        'payments',
        'pickups',
        'pickup_dates',
        'notification_drafts',
        'round_product',
        'round_participants',
        'rounds',
    ];

    /**
     * Order proposals change shape: a position combines several package
     * sizes, amounts can be set by hand, and the suppliers' feedback
     * (prices, shipping) is recorded once per round. Rounds from the first
     * test run don't fit that model, so they are cleared — accounts, groups,
     * memberships and the catalog stay.
     */
    public function up(): void
    {
        $this->clearRounds();

        Schema::drop('proposal_votes');
        Schema::drop('proposal_allocations');
        Schema::drop('proposal_items');
        Schema::drop('order_proposals');

        Schema::create('order_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposed_by_user_id')->constrained('users');
            $table->string('title');
            $table->string('status', 16)->default('draft');
            $table->text('description')->nullable();
            $table->json('shipping_by_supplier')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['round_id', 'status']);
        });

        Schema::create('proposal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('order_proposals')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('portion_size', 8, 3)->nullable();
            $table->decimal('rounding_step', 8, 3)->nullable();
            $table->boolean('packages_fixed')->default(false);
            $table->unsignedInteger('total_price_cents')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['proposal_id', 'product_id']);
        });

        Schema::create('proposal_item_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_tier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->string('article_number', 64)->nullable();
            $table->decimal('package_amount', 12, 3);
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('list_price_cents');
            $table->unsignedInteger('min_order_packages')->default(1);
            $table->unsignedInteger('count');
            $table->timestamps();
        });

        Schema::create('proposal_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->decimal('quantity', 12, 3);
            $table->unsignedInteger('share_cents')->default(0);
            $table->boolean('is_manual')->default(false);
            $table->json('package_counts')->nullable();
            $table->timestamps();

            $table->unique(['proposal_item_id', 'user_id']);
        });

        Schema::create('proposal_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('value', 8);
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['proposal_item_id', 'user_id']);
        });

        Schema::create('round_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->timestamp('inquired_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->unsignedInteger('shipping_cents')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['round_id', 'supplier_id']);
        });

        Schema::create('round_package_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_tier_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('price_cents')->nullable();
            // The catalog price the supplier's answer was recorded against.
            $table->unsignedInteger('list_price_cents')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['round_id', 'price_tier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('round_package_prices');
        Schema::dropIfExists('round_suppliers');

        $this->clearRounds();

        Schema::drop('proposal_votes');
        Schema::drop('proposal_allocations');
        Schema::drop('proposal_item_packages');
        Schema::drop('proposal_items');
        Schema::drop('order_proposals');

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
            $table->foreignId('price_tier_id')->nullable()->constrained('price_tiers')->nullOnDelete();
            $table->string('tier_label')->nullable();
            $table->decimal('package_amount', 12, 3)->nullable();
            $table->unsignedInteger('package_price_cents')->nullable();
            $table->boolean('is_divisible')->default(true);
            $table->decimal('divisible_step', 8, 3)->nullable();
            $table->unsignedInteger('min_order_packages')->default(1);
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
            $table->string('value', 8);
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['proposal_item_id', 'user_id']);
        });
    }

    /**
     * Removes every round with its carts, proposals, payments, notes,
     * documents (including the files) and history.
     */
    private function clearRounds(): void
    {
        $roundTypes = ['App\Models\Round', 'App\Models\OrderProposal'];

        DB::table('attachments')->whereIn('attachable_type', $roundTypes)->orderBy('id')->each(function (object $attachment): void {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });

        DB::table('attachments')->whereIn('attachable_type', $roundTypes)->delete();
        DB::table('notes')->whereIn('notable_type', $roundTypes)->delete();
        DB::table('activities')->whereIn('subject_type', $roundTypes)->delete();
        DB::table('price_observations')->whereNotNull('round_id')->update(['round_id' => null]);

        foreach (self::ROUND_TABLES as $table) {
            DB::table($table)->delete();
        }
    }
};
