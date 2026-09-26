<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pickup dates become time windows: "Saturday 10:00–12:00".
     */
    public function up(): void
    {
        Schema::table('pickup_dates', function (Blueprint $table) {
            $table->dateTime('ends_at')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('pickup_dates', function (Blueprint $table) {
            $table->dropColumn('ends_at');
        });
    }
};
