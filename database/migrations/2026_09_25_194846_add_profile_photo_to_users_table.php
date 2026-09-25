<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mmoollllee\FilamentUserProfile\UserProfile;

return new class extends Migration
{
    /**
     * Adds the photo column to the users table — unless an earlier photo
     * feature already created it under the configured name.
     */
    public function up(): void
    {
        $table = (new (UserProfile::userModel()))->getTable();
        $column = UserProfile::photoColumn();

        if (Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->string($column, 2048)->nullable();
        });
    }

    public function down(): void
    {
        $table = (new (UserProfile::userModel()))->getTable();
        $column = UserProfile::photoColumn();

        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropColumn($column);
        });
    }
};
