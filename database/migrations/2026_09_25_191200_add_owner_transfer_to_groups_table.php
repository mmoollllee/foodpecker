<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The owner hands the group over only with the consent of the new
     * owner: the request waits here until it is accepted or declined.
     */
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('pending_owner_id')->nullable()->after('owner_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('owner_transfer_requested_at')->nullable()->after('pending_owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_owner_id');
            $table->dropColumn('owner_transfer_requested_at');
        });
    }
};
