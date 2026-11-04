<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Permissions;

use Illuminate\Database\Eloquent\Model;

class RolePermissionGrant
{
    /** @var Model */
    private $role;

    /** @var Model */
    private $permission;

    public function __construct(Model $role, Model $permission)
    {
        $this->role = $role;
        $this->permission = $permission;
    }

    public function role(): Model
    {
        return $this->role;
    }

    public function permission(): Model
    {
        return $this->permission;
    }
}
