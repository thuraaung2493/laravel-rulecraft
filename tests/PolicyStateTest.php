<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RolePermissionRule;
use Thuraaung\Rulecraft\Models\Rule;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Tests\Fixtures\CustomerFunctions;
use Thuraaung\Rulecraft\Tests\Fixtures\RecordingAction;
use Thuraaung\Rulecraft\Tests\Fixtures\User;

class PolicyStateTest extends TestCase
{
    public function test_model_and_database_defaults_are_enabled(): void
    {
        $group = $this->group('approval', ['true']);
        $this->assertSame(true, $group->enabled);
        $this->assertSame(true, $group->fresh()->enabled);
        $this->assertSame(true, $group->rules()->first()->enabled);

        $id = DB::table(config('rulecraft.tables.rule_groups'))->insertGetId([
            'name' => 'raw-defaults', 'match' => 'all',
        ]);
        DB::table(config('rulecraft.tables.rules'))->insert([
            'rule_group_id' => $id, 'name' => 'raw-rule', 'expression' => 'true',
        ]);
        $raw = RuleGroup::findOrFail($id);
        $this->assertSame(true, $raw->enabled);
        $this->assertSame(true, $raw->rules()->first()->enabled);
        $this->assertTrue(Rulecraft::group('raw-defaults')->evaluate([])->passed());
    }

    public function test_disabled_root_skips_context_expressions_descendants_and_actions(): void
    {
        $group = $this->group('approval', ['eligible(score)']);
        $group->rules()->first()->update(['action' => 'unregistered']);
        $group->groups()->create(['name' => 'child']);
        $group->update(['enabled' => false]);
        config([
            'rulecraft.expression_providers' => [CustomerFunctions::class],
            'rulecraft.context_rules' => ['approval' => ['amount' => 'required']],
        ]);
        CustomerFunctions::$calls = 0;
        Rulecraft::clearResolvedInstances();

        $execution = Rulecraft::group('approval')->run([]);
        $result = $execution->evaluation();
        $this->assertTrue($result->disabled());
        $this->assertTrue($result->failed());
        $this->assertFalse($result->hasErrors());
        $this->assertSame([], $result->validationErrors());
        $this->assertCount(0, $result->rules());
        $this->assertCount(0, $result->groups());
        $this->assertCount(0, $result->eligibleRules());
        $this->assertTrue($execution->failed());
        $this->assertCount(0, $execution->actions());
        $this->assertSame(0, CustomerFunctions::$calls);

        $group->update(['enabled' => true]);
        $result = Rulecraft::group('approval')->evaluate(['amount' => 100, 'score' => 700]);
        $this->assertFalse($result->disabled());
        $this->assertSame(1, CustomerFunctions::$calls);
    }

    public function test_disabled_rules_still_block_all_groups_without_expression_errors(): void
    {
        $group = $this->group('approval', ['unknown > 0', 'true']);
        $group->rules()->first()->update(['enabled' => false]);
        $result = Rulecraft::group('approval')->evaluate([]);

        $this->assertTrue($result->failed());
        $this->assertFalse($result->disabled());
        $this->assertFalse($result->hasErrors());
        $this->assertCount(2, $result->rules());
        $this->assertTrue($result->failedRules()->first()->disabled());
        $this->assertNull($result->failedRules()->first()->error());
        $this->assertCount(0, $result->eligibleRules());
    }

    public function test_any_groups_only_run_actions_from_enabled_matching_rules(): void
    {
        $group = $this->group('approval', ['unknown > 0', 'true'], 'any');
        $rules = $group->rules()->get();
        $rules[0]->update(['enabled' => false, 'action' => 'unregistered']);
        $rules[1]->update(['action' => 'record']);
        $handler = new RecordingAction();
        $this->app->instance(RecordingAction::class, $handler);
        config(['rulecraft.actions' => ['record' => RecordingAction::class]]);
        $execution = Rulecraft::group('approval')->run([]);

        $this->assertTrue($execution->successful());
        $this->assertFalse($execution->evaluation()->hasErrors());
        $this->assertCount(1, $execution->evaluation()->matchedRules());
        $this->assertCount(1, $execution->evaluation()->eligibleRules());
        $this->assertCount(1, $handler->calls);
        $this->assertSame('condition-1', $execution->actions()->first()->rule()->name);
    }

    public function test_groups_with_no_enabled_conditions_never_pass(): void
    {
        foreach (['all', 'any'] as $match) {
            $group = $this->group($match, ['true'], $match);
            $group->rules()->first()->update(['enabled' => false]);
            $child = $group->groups()->create([
                'name' => $match.'-child', 'enabled' => false,
            ]);
            $child->rules()->create(['name' => 'true', 'expression' => 'true']);
            $result = Rulecraft::group($match)->evaluate([]);
            $this->assertTrue($result->failed());
            $this->assertFalse($result->hasErrors());
            $this->assertCount(0, $result->matchedRules());
            $this->assertCount(0, $result->eligibleRules());
        }
    }

    public function test_disabled_children_are_false_for_both_match_modes(): void
    {
        foreach (['all', 'any'] as $match) {
            $root = $this->group($match, ['true'], $match);
            $child = $root->groups()->create([
                'name' => $match.'-child', 'enabled' => false,
            ]);
            $child->rules()->create(['name' => 'broken', 'expression' => 'unknown > 0']);
            $child->groups()->create(['name' => $match.'-grandchild']);
            config(['rulecraft.context_rules' => [$child->name => ['amount' => 'required']]]);
            $result = Rulecraft::group($match)->evaluate([]);
            $this->assertSame($match === 'any', $result->passed());
            $this->assertFalse($result->hasErrors());
            $disabled = $result->groups()->get($child->name);
            $this->assertTrue($disabled->disabled());
            $this->assertTrue($disabled->failed());
            $this->assertSame([], $disabled->validationErrors());
            $this->assertCount(0, $disabled->rules());
            $this->assertCount(0, $disabled->groups());
        }
    }

    public function test_a_disabled_ancestor_blocks_direct_subtree_selection(): void
    {
        $root = $this->group('root', ['false']);
        $parent = $root->groups()->create(['name' => 'parent']);
        $child = $parent->groups()->create(['name' => 'child']);
        $child->rules()->create(['name' => 'true', 'expression' => 'true']);
        $root->update(['enabled' => false]);

        $result = Rulecraft::group('child')->evaluate([]);
        $this->assertTrue($result->group()->enabled);
        $this->assertTrue($result->disabled());
        $this->assertTrue($result->failed());
        $this->assertFalse($result->hasErrors());
        $this->assertCount(0, $result->allRules());

        $root->update(['enabled' => true]);
        $this->assertTrue(Rulecraft::group('child')->evaluate([])->passed());
    }

    public function test_disabling_boolean_conditions_never_creates_a_new_match(): void
    {
        foreach (['all', 'any'] as $match) {
            $combinations = [['true', 'true'], ['true', 'false'], ['false', 'false']];

            foreach ($combinations as $index => $rules) {
                $name = $match.'-'.$index;
                $group = $this->group($name, $rules, $match);
                $before = Rulecraft::group($name)->evaluate([])->passed();
                foreach ($group->rules()->get() as $rule) {
                    $rule->update(['enabled' => false]);
                    $after = Rulecraft::group($name)->evaluate([])->passed();
                    $this->assertFalse(! $before && $after);
                    $rule->update(['enabled' => true]);
                }
            }
        }
    }

    public function test_disabled_conditions_respect_fail_fast(): void
    {
        $group = $this->group('approval', ['true', 'unknown > 0']);
        $group->rules()->first()->update(['enabled' => false]);
        $result = Rulecraft::group('approval')->stopOnFailure()->evaluate([]);
        $this->assertTrue($result->failed());
        $this->assertFalse($result->hasErrors());
        $this->assertCount(1, $result->rules());
        $this->assertTrue($result->rules()->first()->disabled());
    }

    public function test_result_states_remain_snapshots_after_model_updates(): void
    {
        $this->group('approval', ['true']);
        $result = Rulecraft::group('approval')->evaluate([]);
        $result->group()->update(['enabled' => false]);
        $result->rules()->first()->rule()->update(['enabled' => false]);
        $this->assertTrue($result->passed());
        $this->assertFalse($result->disabled());
        $this->assertTrue($result->rules()->first()->passed());
        $this->assertFalse($result->rules()->first()->disabled());
        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->disabled());
    }

    public function test_cli_labels_disabled_nodes_and_uses_decision_exit_codes(): void
    {
        $group = $this->group('approval', ['true', 'true'], 'any');
        $group->rules()->first()->update(['enabled' => false]);
        $this->artisan('rulecraft:test', ['group' => 'approval', '--data' => '{}'])
            ->expectsOutput('PASS  approval [ANY]')
            ->expectsOutput('  DISABLED  condition-0')
            ->expectsOutput('  PASS  condition-1')
            ->assertExitCode(0);

        $group->update(['enabled' => false]);
        $this->artisan('rulecraft:test', ['group' => 'approval', '--data' => '{}'])
            ->expectsOutput('DISABLED  approval [ANY]')
            ->assertExitCode(1);
    }

    public function test_blade_group_checks_follow_current_enabled_state(): void
    {
        $group = $this->group('approval', ['true']);
        $blade = $this->app->make('blade.compiler');
        $group->update(['enabled' => false]);
        $this->assertFalse($blade->check('rulecraft', 'approval', []));
        $group->update(['enabled' => true]);
        $this->assertTrue($blade->check('rulecraft', 'approval', []));
    }

    public function test_permission_mappings_are_preserved_while_a_group_is_disabled(): void
    {
        $this->installPermissions();
        $user = User::create(['name' => 'Manager']);
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'approve', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $group = $this->group('approval', ['true']);
        $mapping = RolePermissionRule::assign($role, $permission, $group);
        $this->actingAs($user);
        $group->update(['enabled' => false]);

        $pending = Rulecraft::for($user)->permission('approve');
        $result = $pending->evaluate([]);
        $this->assertTrue($result->failed());
        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->evaluations()->get($mapping->getKey())->disabled());
        $blade = $this->app->make('blade.compiler');
        $this->assertFalse($blade->check('canRulecraft', 'approve', []));
        $this->assertTrue($user->hasPermissionTo('approve'));
        $this->assertNotNull($mapping->fresh());

        $group->update(['enabled' => true]);
        $this->assertTrue($pending->evaluate([])->passed());
        $this->assertTrue($blade->check('canRulecraft', 'approve', []));
    }

    public function test_initial_schema_recreates_enabled_defaults_after_rollback(): void
    {
        $group = $this->group('approval', ['true']);
        $group->update(['enabled' => false]);
        $group->rules()->first()->update(['enabled' => false]);
        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
        foreach (config('rulecraft.tables') as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        foreach (['rule_groups', 'rules'] as $key) {
            $this->assertTrue(Schema::hasColumn(config('rulecraft.tables.'.$key), 'enabled'));
        }
        $this->assertSame(0, RuleGroup::count());
        $this->assertSame(0, Rule::count());
        $group = $this->group('approval', ['true']);
        $this->assertTrue($group->fresh()->enabled);
        $this->assertTrue($group->rules()->first()->enabled);
        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->passed());
    }
}
