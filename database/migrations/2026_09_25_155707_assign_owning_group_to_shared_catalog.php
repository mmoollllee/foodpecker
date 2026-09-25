<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `group_id` on manufacturers and products now always names the owning
     * group; `visibility` alone decides whether other groups can use it.
     * Public records created before this change get the current group of
     * their creator as owner.
     */
    public function up(): void
    {
        foreach (['manufacturers', 'products'] as $table) {
            DB::table($table)
                ->whereNull('group_id')
                ->whereNotNull('created_by_user_id')
                ->orderBy('id')
                ->each(function (object $record) use ($table): void {
                    $groupId = DB::table('users')->where('id', $record->created_by_user_id)->value('current_group_id')
                        ?? DB::table('group_user')->where('user_id', $record->created_by_user_id)->orderBy('id')->value('group_id');

                    if ($groupId !== null) {
                        DB::table($table)->where('id', $record->id)->update(['group_id' => $groupId]);
                    }
                });
        }
    }

    public function down(): void
    {
        //
    }
};
