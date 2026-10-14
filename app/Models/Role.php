<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/** A staff role. Registered as spatie's role model in config/permission.php. */
class Role extends SpatieRole
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_locked' => 'boolean'];
    }
}
