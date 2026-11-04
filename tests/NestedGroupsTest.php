<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Thuraaung\Rulecraft\Exceptions\InvalidGroupHierarchyException;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\Rule;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Tests\Fixtures\RecordingAction;

class NestedGroupsTest extends TestCase
{
    /**
     * @param  array<int, string>  $expressions
     */
    private function child(
        RuleGroup $parent,
        string $name,
        array $expressions = [],
        string $match = 'all'
    ): RuleGroup {
        $group = $parent->groups()->create(['name' => $name, 'match' => $match]);

        foreach ($expressions as $index => $expression) {
            $group->rules()->create(['name' => $name.'-'.$index, 'expression' => $expression]);
        }

        return $group;
    }

    public function test_a_rule_and_an_any_subgroup_form_the_same_branch_approval_condition(): void
    {
        $root = $this->group('approval', ['loan_branch_id == user_branch_id']);
        $child = $this->child($root, 'limit-or-special', [
            'loan_amount <= approval_limit', 'special_approval == true',
        ], 'any');
        $base = [
            'loan_branch_id' => 1, 'user_branch_id' => 1,
            'loan_amount' => 100, 'approval_limit' => 200, 'special_approval' => false,
        ];

        foreach ([
            [[], true],
            [['loan_amount' => 300, 'special_approval' => true], true],
            [['loan_amount' => 300], false],
            [['user_branch_id' => 2, 'special_approval' => true], false],
        ] as $case) {
            $result = Rulecraft::group('approval')->evaluate(array_replace($base, $case[0]));
            $this->assertSame($case[1], $result->passed());
        }

        $result = Rulecraft::group('approval')->evaluate($base);
        $this->assertTrue($result->group()->is($root));
        $this->assertCount(1, $result->rules());
        $this->assertCount(3, $result->allRules());
        $this->assertTrue($result->groups()->get('limit-or-special')->group()->is($child));
        $this->assertCount(2, $result->groups()->get('limit-or-special')->rules());
        $this->assertTrue($child->parent->is($root));
    }

    public function test_the_three_branch_loan_example_supports_individual_leaf_rules(): void
    {
        $root = $this->group('eligibility', [], 'any');
        $this->child($root, 'green-zone', ['zone_type == "Green"', 'potential_risk_zone == 0']);
        $this->child($root, 'risk-region', [
            'potential_risk_zone == 1',
            'region_name in ["Region 1- A", "Region 1- B", "Region 2"]',
        ]);
        $products = [
            2,
            5,
            8,
            11,
            18,
            19,
            21,
            25,
            26,
            30,
            31,
            36,
            37,
            38,
            39,
            43,
            44,
            45,
            48,
            49,
            60,
            62,
            63,
            64,
            87,
            88,
            92,
        ];
        $this->child($root, 'products', ['product_id in ['.implode(',', $products).']']);
        $base = [
            'zone_type' => 'Red',
            'potential_risk_zone' => 0,
            'region_name' => 'Other',
            'product_id' => 999,
        ];
        $cases = [
            [['zone_type' => 'Green'], true],
            [['zone_type' => 'Green', 'potential_risk_zone' => 1], false],
            [['region_name' => 'Region 2'], false],
            [['potential_risk_zone' => 1, 'region_name' => 'Region 2 Extra'], false],
            [[], false],
        ];

        foreach (['Region 1- A', 'Region 1- B', 'Region 2'] as $region) {
            $cases[] = [['potential_risk_zone' => 1, 'region_name' => $region], true];
        }

        foreach ($products as $product) {
            $cases[] = [['potential_risk_zone' => 2, 'product_id' => $product], true];
        }

        foreach ($cases as $case) {
            $result = Rulecraft::group('eligibility')->evaluate(array_replace($base, $case[0]));
            $this->assertSame($case[1], $result->passed());
            $this->assertCount(3, $result->groups());
            $this->assertCount(5, $result->allRules());
        }
    }

    public function test_nested_expression_errors_propagate_even_when_another_branch_passes(): void
    {
        $root = $this->group('approval', [], 'any');
        $this->child($root, 'passing', ['true']);
        $this->child($root, 'broken', ['true', 'missing > 1'], 'any');

        $result = Rulecraft::group('approval')->evaluate([]);
        $this->assertTrue($result->failed());
        $this->assertTrue($result->hasErrors());
        $this->assertCount(1, $result->errors());
        $this->assertCount(1, $result->failedRules());
        $this->assertTrue($result->groups()->get('broken')->hasErrors());
    }

    public function test_empty_child_groups_fail_and_an_any_parent_can_use_another_branch(): void
    {
        $root = $this->group('approval', []);
        $this->child($root, 'empty');
        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->failed());

        $root->update(['match' => 'any']);
        $this->child($root, 'passing', ['true']);
        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->passed());
    }

    public function test_run_excludes_matched_rules_in_failed_branches_at_every_level(): void
    {
        $root = $this->group('approval', ['true'], 'any');
        $root->rules()->first()->update([
            'action' => 'record', 'action_parameters' => ['output' => ['previous' => 'root']],
        ]);
        $failed = $this->child($root, 'failed', ['true', 'false']);
        $failed->rules()->first()->update(['action' => 'unregistered']);
        $passing = $this->child($root, 'passing', ['true'], 'any');
        $passing->rules()->first()->update(['action' => 'record']);
        $failedDescendant = $this->child($passing, 'failed-descendant', ['true', 'false']);
        $failedDescendant->rules()->first()->update(['action' => 'unregistered']);
        $handler = new RecordingAction();
        $this->app->instance(RecordingAction::class, $handler);
        config(['rulecraft.actions' => ['record' => RecordingAction::class]]);

        $execution = Rulecraft::group('approval')->run([]);
        $this->assertTrue($execution->successful());
        $this->assertCount(4, $execution->evaluation()->matchedRules());
        $this->assertCount(2, $execution->evaluation()->eligibleRules());
        $this->assertCount(2, $execution->actions());
        $this->assertSame(
            ['condition-0', 'passing-0'],
            $execution->actions()->map(function ($outcome) {
                return $outcome->rule()->name;
            })->all()
        );
        $this->assertSame('root', $handler->calls[1]['context']['previous']);
    }

    public function test_passing_child_actions_wait_for_the_root_to_pass(): void
    {
        $root = $this->group('approval', ['false']);
        $child = $this->child($root, 'passing', ['true']);
        $child->rules()->first()->update(['action' => 'unregistered']);
        $execution = Rulecraft::group('approval')->run([]);

        $this->assertTrue($execution->failed());
        $this->assertCount(0, $execution->actions());
        $this->assertCount(0, $execution->evaluation()->eligibleRules());
    }

    public function test_stop_on_failure_skips_groups_without_changing_the_original_request(): void
    {
        $root = $this->group('approval', []);
        $this->child($root, 'first', ['false']);
        $this->child($root, 'later', ['missing > 1']);
        $pending = Rulecraft::group('approval');

        $stopped = $pending->stopOnFailure()->evaluate([]);
        $this->assertTrue($stopped->failed());
        $this->assertCount(1, $stopped->groups());
        $this->assertFalse($stopped->hasErrors());
        $this->assertCount(2, $pending->evaluate([])->groups());
        $this->assertTrue($pending->evaluate([])->hasErrors());
    }

    public function test_fail_fast_does_not_stop_any_subgroups_on_a_false_rule(): void
    {
        $root = $this->group('approval', []);
        $this->child($root, 'alternative', ['false', 'true'], 'any');
        $result = Rulecraft::group('approval')->stopOnFailure()->evaluate([]);

        $this->assertTrue($result->passed());
        $this->assertCount(2, $result->allRules());
    }

    public function test_groups_cannot_be_their_own_parent(): void
    {
        $root = $this->group('approval', []);
        $this->expectException(InvalidGroupHierarchyException::class);
        $root->update(['parent_id' => $root->getKey()]);
    }

    public function test_groups_cannot_be_moved_under_a_descendant(): void
    {
        $root = $this->group('approval', []);
        $child = $this->child($root, 'child');
        $grandchild = $this->child($child, 'grandchild');
        $this->expectException(InvalidGroupHierarchyException::class);
        $root->update(['parent_id' => $grandchild->getKey()]);
    }

    public function test_runtime_rejects_cycles_introduced_by_direct_database_writes(): void
    {
        $root = $this->group('approval', []);
        $child = $this->child($root, 'child');
        RuleGroup::query()->whereKey($root->getKey())->update(['parent_id' => $child->getKey()]);

        $this->expectException(InvalidGroupHierarchyException::class);
        Rulecraft::group('approval')->evaluate([]);
    }

    public function test_depth_limit_includes_the_root_when_creating_children(): void
    {
        config(['rulecraft.max_depth' => 2]);
        $root = $this->group('approval', []);
        $child = $this->child($root, 'child');
        $this->assertTrue($child->parent->is($root));

        $this->expectException(InvalidGroupHierarchyException::class);
        $this->child($child, 'too-deep');
    }

    public function test_moving_a_subtree_checks_the_depth_of_every_descendant(): void
    {
        config(['rulecraft.max_depth' => 3]);
        $root = $this->group('approval', []);
        $child = $this->child($root, 'child');
        $this->child($child, 'grandchild');
        $newParent = $this->group('new-parent', []);

        try {
            $root->update(['parent_id' => $newParent->getKey()]);
            $this->fail('Expected an excessive-depth exception.');
        } catch (InvalidGroupHierarchyException $error) {
            $this->assertNull($root->fresh()->parent_id);
        }
    }

    public function test_runtime_enforces_a_lowered_depth_limit(): void
    {
        $root = $this->group('approval', []);
        $child = $this->child($root, 'child');
        $this->child($child, 'grandchild', ['true']);
        config(['rulecraft.max_depth' => 2]);

        $this->expectException(InvalidGroupHierarchyException::class);
        Rulecraft::group('approval')->evaluate([]);
    }

    public function test_older_configuration_without_the_new_depth_key_uses_the_default(): void
    {
        config(['rulecraft' => array_diff_key(config('rulecraft'), ['max_depth' => true])]);
        $root = $this->group('approval', []);
        $this->child($root, 'child', ['true']);

        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->passed());
    }

    public function test_the_default_limit_can_be_increased_for_deeper_trees(): void
    {
        config(['rulecraft.max_depth' => 12]);
        $root = $this->group('approval', []);
        $parent = $root;
        for ($level = 2; $level <= 12; $level++) {
            $parent = $this->child($parent, 'level-'.$level);
        }
        $parent->rules()->create(['name' => 'leaf', 'expression' => 'true']);

        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->passed());
        $this->assertCount(1, Rulecraft::group('approval')->evaluate([])->allRules());
    }

    public function test_parent_deletion_cascades_to_descendant_groups_and_rules(): void
    {
        $root = $this->group('approval', ['true']);
        $child = $this->child($root, 'child', ['true']);
        $this->child($child, 'grandchild', ['true']);
        // Use a bulk delete to verify database constraints, not model callbacks.
        RuleGroup::query()->whereKey($root->getKey())->delete();

        $this->assertSame(0, RuleGroup::count());
        $this->assertSame(0, Rule::count());
    }

    public function test_one_initial_migration_creates_and_rebuilds_the_complete_hierarchy(): void
    {
        $packageMigrations = DB::table('migrations')->where('migration', 'like', '%rulecraft%');
        $this->assertSame(1, $packageMigrations->count());
        $root = $this->group('approval', ['true']);
        $child = $this->child($root, 'child', ['true']);
        $this->child($child, 'grandchild', ['true']);
        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
        foreach (config('rulecraft.tables') as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertSame(0, $packageMigrations->count());

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame(1, $packageMigrations->count());
        $this->assertTrue(Schema::hasColumn(config('rulecraft.tables.rule_groups'), 'parent_id'));
        $this->assertSame(0, RuleGroup::count());
        $this->assertSame(0, Rule::count());
        $root = $this->group('approval', ['true']);
        $child = $this->child($root, 'child', ['true']);
        $this->assertNull($root->parent_id);
        $this->assertTrue($child->parent->is($root));
        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->passed());
        RuleGroup::query()->whereKey($root->getKey())->delete();
        $this->assertSame(0, RuleGroup::count());
        $this->assertSame(0, Rule::count());
    }
}
