<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Contracts;

use Thuraaung\Rulecraft\Permissions\RolePermissionGrant;

interface GrantResolver
{
    /**
     * @param  mixed  $user
     * @return iterable<RolePermissionGrant> Actual grants for this user and permission.
     */
    public function grants($user, string $permission, ?string $guard = null): iterable;
}
