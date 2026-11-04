<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Foundation\Application;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RolePermissionRule;
use Thuraaung\Rulecraft\Tests\Fixtures\User;

class TeamsTest extends TestCase
{
    /** @param Application $app */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('permission.teams', true);
    }

    public function test_team_switches_do_not_reuse_previously_loaded_roles(): void
    {
        $this->installPermissions();
        $user = User::create(['name' => 'Manager']);
        $permission = Permission::create(['name' => 'loan-approval', 'guard_name' => 'web']);

        setPermissionsTeamId(1);
        $role = Role::create(['name' => 'branch-manager', 'guard_name' => 'web', 'team_id' => 1]);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        RolePermissionRule::assign($role, $permission, $this->group('approval', ['true']));
        $user->load('roles');
        $pending = Rulecraft::for($user)->permission('loan-approval');
        $this->assertTrue($pending->evaluate([])->passed());

        try {
            setPermissionsTeamId(2);
            $this->assertTrue($pending->evaluate([])->failed());
        } finally {
            setPermissionsTeamId(null);
        }
    }
}
