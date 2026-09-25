<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where somebody lives and for how many people they shop — asked at
     * registration, together with the mobile number.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('postal_code', 10)->nullable()->after('phone');
            $table->string('city')->nullable()->after('postal_code');
            $table->unsignedTinyInteger('household_size')->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['postal_code', 'city', 'household_size']);
        });
    }
};
