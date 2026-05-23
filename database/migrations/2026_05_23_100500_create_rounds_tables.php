<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_user_id')->constrained('users');
            $table->string('title');
            $table->string('phase', 32)->default('draft');
            $table->text('description')->nullable();

            // Deadlines
            $table->date('shopping_deadline')->nullable();
            $table->date('negotiation_deadline')->nullable();
            $table->date('finalization_deadline')->nullable();
            $table->date('payment_deadline')->nullable();
            $table->date('expected_delivery')->nullable();

            // Logistik
            $table->text('pickup_location')->nullable();
            $table->unsignedSmallInteger('max_participants')->nullable();

            // Geld
            $table->decimal('lead_fee_percent', 5, 2)->default(0);
            $table->decimal('platform_fee_percent', 5, 2)->default(1.0);
            $table->unsignedInteger('chosen_proposal_id')->nullable();

            $table->timestamp('phase_changed_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'phase']);
        });

        Schema::create('round_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('removed')->default(false);
            $table->string('remove_reason')->nullable();
            $table->unsignedInteger('round_up_to_cents')->nullable();
            $table->timestamps();

            $table->unique(['round_id', 'user_id']);
        });

        Schema::create('pickup_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->dateTime('scheduled_at');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_dates');
        Schema::dropIfExists('round_participants');
        Schema::dropIfExists('rounds');
    }
};
