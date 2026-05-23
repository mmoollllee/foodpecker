<?php

namespace App\Models;

use App\Enums\GroupRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

class GroupUser extends Pivot
{
    protected $table = 'group_user';

    public $incrementing = true;

    protected $fillable = ['group_id', 'user_id', 'role', 'joined_at'];

    protected function casts(): array
    {
        return [
            'role' => GroupRole::class,
            'joined_at' => 'datetime',
        ];
    }
}
