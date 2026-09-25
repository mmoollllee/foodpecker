<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_drafts', function (Blueprint $table) {
            $table->foreignId('sent_by_user_id')->nullable()->after('sent_at')
                ->constrained('users')->nullOnDelete();
            $table->unsignedInteger('recipient_count')->nullable()->after('sent_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('notification_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by_user_id');
            $table->dropColumn('recipient_count');
        });
    }
};
