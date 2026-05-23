<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('round_up_donation_cents')->default(0);
            $table->string('status', 16)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['round_id', 'user_id']);
        });

        Schema::create('pickups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('pickup_date_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('picked_up_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['round_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickups');
        Schema::dropIfExists('payments');
    }
};
