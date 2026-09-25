<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A lead hands the round over only with the consent of the new lead:
     * the request waits here until it is accepted or declined.
     */
    public function up(): void
    {
        Schema::table('rounds', function (Blueprint $table) {
            $table->foreignId('pending_lead_user_id')->nullable()->after('lead_user_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('lead_handover_requested_at')->nullable()->after('pending_lead_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('rounds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_lead_user_id');
            $table->dropColumn('lead_handover_requested_at');
        });
    }
};
