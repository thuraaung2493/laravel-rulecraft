<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Permissions;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Spatie\Permission\Guard;
use Thuraaung\Rulecraft\Contracts\GrantResolver;

class SpatieGrantResolver implements GrantResolver
{
    /**
     * @param  mixed  $user
     * @return iterable<RolePermissionGrant>
     */
    public function grants($user, string $permission, ?string $guard = null): iterable
    {
        if (! class_exists(Guard::class)) {
            throw new LogicException(
                'Install a compatible spatie/laravel-permission version '
                .'or configure a custom GrantResolver.'
            );
        }

        if (! $user instanceof Model || ! method_exists($user, 'roles')) {
            throw new InvalidArgumentException(
                'The user must be an Eloquent model using Spatie HasRoles.'
            );
        }

        $guard = $guard ?? Guard::getDefaultName($user);
        $roles = $user->roles();

        // Query the relationship afresh so changed membership and Spatie team scope apply.
        foreach ($roles->where(
            $roles->getRelated()->qualifyColumn('guard_name'),
            $guard
        )->get() as $role) {
            $permissions = $role->permissions();
            $model = $permissions->getRelated();

            foreach ($permissions->where($model->qualifyColumn('name'), $permission)
                ->where($model->qualifyColumn('guard_name'), $guard)->get() as $granted) {
                yield new RolePermissionGrant($role, $granted);
            }
        }
    }
}
