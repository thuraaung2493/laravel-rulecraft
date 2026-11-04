<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Thuraaung\Rulecraft\Exceptions\InvalidGroupHierarchyException;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RolePermissionRule;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Tests\Fixtures\RecordingAction;
use Thuraaung\Rulecraft\Tests\Fixtures\User;

class PermissionsTest extends TestCase
{
    /** @var User */
    private $user;

    /** @var Permission */
    private $permission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installPermissions();
        $this->user = User::create(['name' => 'Manager']);
        $this->permission = Permission::create(['name' => 'loan-approval', 'guard_name' => 'web']);
    }

    private function grant(string $name = 'branch-manager'): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->givePermissionTo($this->permission);
        $this->user->assignRole($role);

        return $role;
    }

    public function test_a_role_permission_assignment_evaluates_its_complete_nested_tree(): void
    {
        $root = $this->group('approval', ['same_branch']);
        $child = $root->groups()->create(['name' => 'limit-or-special', 'match' => 'any']);
        $child->rules()->createMany([
            ['name' => 'limit', 'expression' => 'within_limit'],
            ['name' => 'special', 'expression' => 'special_approval', 'action' => 'unregistered'],
        ]);
        RolePermissionRule::assign($this->grant(), $this->permission, $root);
        $pending = Rulecraft::for($this->user)->permission('loan-approval');

        $result = $pending->evaluate(
            ['same_branch' => true, 'within_limit' => false, 'special_approval' => true]
        );
        $this->assertTrue($result->passed());
        $this->assertCount(1, $result->evaluations()->first()->groups());
        $this->assertTrue($pending->evaluate([
            'same_branch' => false, 'within_limit' => false, 'special_approval' => true,
        ])->failed());
    }

    public function test_assigning_a_permission_directly_to_a_child_group_is_rejected(): void
    {
        $root = $this->group('approval', []);
        $child = $root->groups()->create(['name' => 'child']);
        $role = $this->grant();

        $this->expectException(InvalidGroupHierarchyException::class);
        RolePermissionRule::assign($role, $this->permission, $child);
    }

    public function test_assigned_root_groups_cannot_be_reparented_through_the_model(): void
    {
        $root = $this->group('approval', ['true']);
        $other = $this->group('other', ['false']);
        RolePermissionRule::assign($this->grant(), $this->permission, $root);

        $this->expectException(InvalidGroupHierarchyException::class);
        $root->update(['parent_id' => $other->getKey()]);
    }

    public function test_raw_reparenting_cannot_bypass_parent_conditions(): void
    {
        $root = $this->group('approval', ['true']);
        $parent = $this->group('parent', ['false']);
        RolePermissionRule::assign($this->grant(), $this->permission, $root);
        RuleGroup::query()->whereKey($root->getKey())->update(['parent_id' => $parent->getKey()]);

        $pending = Rulecraft::for($this->user)->permission('loan-approval');
        $this->assertTrue($pending->evaluate([])->failed());
        $this->assertTrue($pending->evaluate([])->hasErrors());

        RolePermissionRule::assign(
            $this->grant('regional-manager'),
            $this->permission,
            $this->group('regional', ['true'])
        );
        $this->assertTrue($pending->evaluate([])->passed());
    }

    public function test_role_permission_group_is_evaluated_without_running_actions(): void
    {
        $group = $this->group();
        $group->rules()->first()->update(['action' => 'record']);
        $handler = new RecordingAction();
        config(['rulecraft.actions' => ['record' => RecordingAction::class]]);
        $this->app->instance(RecordingAction::class, $handler);
        $mapping = RolePermissionRule::assign($this->grant(), $this->permission, $group);

        $pending = Rulecraft::for($this->user)->permission('loan-approval');
        $result = $pending->evaluate(['amount' => 100, 'limit' => 200]);
        $this->assertTrue($result->passed());
        $this->assertTrue($result->evaluations()->has($mapping->getKey()));
        $this->assertTrue($pending->evaluate(['amount' => 300, 'limit' => 200])->failed());
        $this->assertSame([], $handler->calls);
        $this->assertTrue($mapping->role->is($this->user->roles()->first()));
        $this->assertTrue($mapping->permission->is($this->permission));
    }

    public function test_mapping_does_not_grant_a_permission_the_role_does_not_have(): void
    {
        $role = Role::create(['name' => 'branch-manager', 'guard_name' => 'web']);
        $this->user->assignRole($role);
        RolePermissionRule::assign($role, $this->permission, $this->group('approval', ['true']));

        $this->assertTrue(
            Rulecraft::for($this->user)->permission('loan-approval')->evaluate([])->failed()
        );
    }

    public function test_unassigned_roles_cannot_authorize_the_user(): void
    {
        $role = Role::create(['name' => 'regional-manager', 'guard_name' => 'web']);
        $role->givePermissionTo($this->permission);
        RolePermissionRule::assign($role, $this->permission, $this->group('approval', ['true']));

        $this->assertTrue(
            Rulecraft::for($this->user)->permission('loan-approval')->evaluate([])->failed()
        );
    }

    public function test_a_permission_and_conditions_from_different_roles_cannot_be_combined(): void
    {
        $roleWithPermission = $this->grant('branch-manager');
        $roleWithoutPermission = Role::create(
            ['name' => 'regional-manager', 'guard_name' => 'web']
        );
        $this->user->assignRole($roleWithoutPermission);
        RolePermissionRule::assign(
            $roleWithPermission,
            $this->permission,
            $this->group('branch', ['false'])
        );
        RolePermissionRule::assign(
            $roleWithoutPermission,
            $this->permission,
            $this->group('regional', ['true'])
        );

        $this->assertTrue(
            Rulecraft::for($this->user)->permission('loan-approval')->evaluate([])->failed()
        );
    }

    public function test_one_complete_passing_grant_is_sufficient_among_multiple_roles(): void
    {
        RolePermissionRule::assign(
            $this->grant('branch-manager'),
            $this->permission,
            $this->group('branch', ['false'])
        );
        RolePermissionRule::assign(
            $this->grant('regional-manager'),
            $this->permission,
            $this->group('regional', ['true'])
        );

        $result = Rulecraft::for($this->user)->permission('loan-approval')->evaluate([]);
        $this->assertTrue($result->passed());
        $this->assertCount(2, $result->evaluations());
    }

    public function test_an_independent_grant_can_pass_when_another_has_errors(): void
    {
        RolePermissionRule::assign(
            $this->grant('branch-manager'),
            $this->permission,
            $this->group('branch', ['missing > 1'])
        );
        $pending = Rulecraft::for($this->user)->permission('loan-approval');
        $this->assertTrue($pending->evaluate([])->failed());
        $this->assertTrue($pending->evaluate([])->hasErrors());

        RolePermissionRule::assign(
            $this->grant('regional-manager'),
            $this->permission,
            $this->group('regional', ['true'])
        );
        $this->assertTrue($pending->evaluate([])->passed());
        $this->assertTrue($pending->evaluate([])->hasErrors());
    }

    public function test_a_grant_without_a_mapping_is_denied(): void
    {
        $this->grant();
        $this->assertTrue(
            Rulecraft::for($this->user)->permission('loan-approval')->evaluate([])->failed()
        );
    }

    public function test_deleting_a_condition_group_revokes_conditional_access(): void
    {
        $group = $this->group('approval', ['true']);
        RolePermissionRule::assign($this->grant(), $this->permission, $group);
        $pending = Rulecraft::for($this->user)->permission('loan-approval');
        $this->assertTrue($pending->evaluate([])->passed());

        $group->delete();
        $this->assertTrue($pending->evaluate([])->failed());
        $this->assertSame(0, RolePermissionRule::count());
    }

    public function test_revoked_grants_are_not_read_from_stale_relations(): void
    {
        $role = $this->grant();
        RolePermissionRule::assign($role, $this->permission, $this->group('approval', ['true']));
        $this->user->load('roles.permissions');
        $pending = Rulecraft::for($this->user)->permission('loan-approval');
        $this->assertTrue($pending->evaluate([])->passed());

        $role->permissions()->detach($this->permission);
        $this->assertTrue($pending->evaluate([])->failed());
        $role->givePermissionTo($this->permission);
        $this->user->roles()->detach($role);
        $this->assertTrue($pending->evaluate([])->failed());
    }

    public function test_guards_and_fluent_selection_are_isolated(): void
    {
        $role = Role::create(['name' => 'branch-manager', 'guard_name' => 'api']);
        $permission = Permission::create(['name' => 'loan-approval', 'guard_name' => 'api']);
        $role->givePermissionTo($permission);
        $this->user->assignRole($role);
        RolePermissionRule::assign($role, $permission, $this->group('approval', ['true']));

        $pending = Rulecraft::for($this->user)->permission('loan-approval');
        $this->assertTrue($pending->evaluate([])->failed());
        $this->assertTrue($pending->guard('api')->evaluate([])->passed());
        $this->assertTrue($pending->evaluate([])->failed());
    }

    public function test_direct_user_permission_does_not_bypass_role_conditions(): void
    {
        $this->user->givePermissionTo($this->permission);
        $this->assertTrue(
            Rulecraft::for($this->user)->permission('loan-approval')->evaluate([])->failed()
        );
    }

    public function test_assignment_updates_one_mapping_and_respects_morph_aliases(): void
    {
        Relation::morphMap(['role' => Role::class, 'permission' => Permission::class]);

        try {
            $role = $this->grant();
            $mapping = RolePermissionRule::assign(
                $role,
                $this->permission,
                $this->group('first', ['false'])
            );
            $replacement = RolePermissionRule::assign(
                $role,
                $this->permission,
                $this->group('second', ['true'])
            );
            $this->assertSame($mapping->getKey(), $replacement->getKey());
            $this->assertSame('role', $replacement->role_type);
            $this->assertSame(1, RolePermissionRule::count());
            $this->assertTrue(
                Rulecraft::for($this->user)->permission('loan-approval')->evaluate([])->passed()
            );
        } finally {
            Relation::morphMap([], false);
        }
    }
}
