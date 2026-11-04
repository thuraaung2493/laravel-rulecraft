<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Thuraaung\Rulecraft\Exceptions\RuleGroupNotFoundException;
use Thuraaung\Rulecraft\Models\RolePermissionRule;
use Thuraaung\Rulecraft\Tests\Fixtures\User;

class BladeTest extends TestCase
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    private function render(string $template, array $data = []): string
    {
        $compiled = $this->app->make('blade.compiler')->compileString($template);
        extract($data);
        ob_start();

        try {
            eval('?>'.$compiled);

            return trim(ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public function test_group_conditions_render_both_branches_without_running_actions(): void
    {
        $group = $this->group();
        $group->rules()->first()->update(['action' => 'unregistered']);
        $template = '@rulecraft("approval", $context)allowed @else denied @endrulecraft';
        $this->assertSame(
            'allowed',
            $this->render($template, ['context' => ['amount' => 1, 'limit' => 2]])
        );
        $this->assertSame(
            'denied',
            $this->render($template, ['context' => ['amount' => 3, 'limit' => 2]])
        );
        $this->assertSame('denied', $this->render($template, ['context' => []]));
    }

    public function test_group_context_defaults_to_an_empty_array(): void
    {
        $this->group('always', ['true']);
        $this->assertSame('yes', $this->render('@rulecraft("always")yes @endrulecraft'));
    }

    public function test_missing_groups_keep_the_normal_exception_behavior(): void
    {
        $this->expectException(RuleGroupNotFoundException::class);
        $this->render('@rulecraft("missing")yes @endrulecraft');
    }

    public function test_guest_permission_condition_denies_without_resolving_grants(): void
    {
        $this->assertSame(
            'guest',
            $this->render('@canRulecraft("Loan Approval")allowed @else guest @endcanRulecraft')
        );
    }

    public function test_permission_condition_requires_a_grant_and_passing_conditions(): void
    {
        $this->installPermissions();
        $user = User::create(['name' => 'Manager']);
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'api']);
        $permission = Permission::create(['name' => 'Loan Approval', 'guard_name' => 'api']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $group = $this->group('approval', ['allowed']);
        $group->rules()->first()->update(['action' => 'unregistered']);
        RolePermissionRule::assign($role, $permission, $group);
        $this->actingAs($user, 'api');
        $template = '@canRulecraft("Loan Approval", $context, "api")yes @else no @endcanRulecraft';
        $this->assertSame('yes', $this->render($template, ['context' => ['allowed' => true]]));
        $this->assertSame('no', $this->render($template, ['context' => ['allowed' => false]]));
        $this->assertSame(
            'yes',
            $this->render('@canRulecraft("Loan Approval", ["allowed" => true])yes @endcanRulecraft')
        );
        $role->revokePermissionTo($permission);
        $this->assertSame('no', $this->render($template, ['context' => ['allowed' => true]]));
    }
}
