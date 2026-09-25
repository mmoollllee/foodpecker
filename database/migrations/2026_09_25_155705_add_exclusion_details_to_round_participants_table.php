<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('round_participants', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('remove_reason');
            $table->foreignId('removed_by_user_id')->nullable()->after('removed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('round_participants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('removed_by_user_id');
            $table->dropColumn('removed_at');
        });
    }
};
