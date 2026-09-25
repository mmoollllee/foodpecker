<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every owner is also a member with the owner role, so membership
     * queries never need to special-case `groups.owner_id`.
     */
    public function up(): void
    {
        DB::table('groups')
            ->whereNotNull('owner_id')
            ->orderBy('id')
            ->each(function (object $group): void {
                $membership = DB::table('group_user')
                    ->where('group_id', $group->id)
                    ->where('user_id', $group->owner_id)
                    ->first();

                if ($membership === null) {
                    DB::table('group_user')->insert([
                        'group_id' => $group->id,
                        'user_id' => $group->owner_id,
                        'role' => 'owner',
                        'joined_at' => $group->created_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return;
                }

                if ($membership->role !== 'owner') {
                    DB::table('group_user')->where('id', $membership->id)->update(['role' => 'owner']);
                }
            });
    }

    public function down(): void
    {
        //
    }
};
