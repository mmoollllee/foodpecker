<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A new version or counter-proposal remembers the proposal it copies, so
     * everybody can see what changed between the two.
     */
    public function up(): void
    {
        Schema::table('order_proposals', function (Blueprint $table) {
            $table->foreignId('based_on_proposal_id')
                ->nullable()
                ->after('proposed_by_user_id')
                ->constrained('order_proposals')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('based_on_proposal_id');
        });
    }
};
