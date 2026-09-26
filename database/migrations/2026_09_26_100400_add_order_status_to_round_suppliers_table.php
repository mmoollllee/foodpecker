<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The lead ticks off per supplier when the order went out and when the
     * goods arrived.
     */
    public function up(): void
    {
        Schema::table('round_suppliers', function (Blueprint $table) {
            $table->timestamp('ordered_at')->nullable()->after('shipping_cents');
            $table->timestamp('delivered_at')->nullable()->after('ordered_at');
        });
    }

    public function down(): void
    {
        Schema::table('round_suppliers', function (Blueprint $table) {
            $table->dropColumn(['ordered_at', 'delivered_at']);
        });
    }
};
