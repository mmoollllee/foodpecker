<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('visibility')->default('private');
            $table->string('website')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->text('address')->nullable();
            $table->text('shipping_notes')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['visibility', 'group_id']);
            $table->unique(['group_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturers');
    }
};
