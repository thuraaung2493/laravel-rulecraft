<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Thuraaung\Rulecraft\Contracts\GrantResolver;
use Thuraaung\Rulecraft\Events\GroupEvaluated;
use Thuraaung\Rulecraft\Events\PermissionEvaluated;
use Thuraaung\Rulecraft\Exceptions\InvalidGroupHierarchyException;
use Thuraaung\Rulecraft\Exceptions\RuleGroupNotFoundException;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RolePermissionRule;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Tests\Fixtures\RecordingAction;
use Thuraaung\Rulecraft\Tests\Fixtures\User;

class EvaluationEventsTest extends TestCase
{
    /** @var array<int, GroupEvaluated> */
    private $groups = [];

    /** @var array<int, PermissionEvaluated> */
    private $permissions = [];

    /** @var array<int, string> */
    private $order = [];

    /** @var array<string, mixed> */
    private $context = ['amount' => 100, 'limit' => 200, 'customer' => ['country' => 'MM']];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(GroupEvaluated::class, function (GroupEvaluated $event): void {
            $this->groups[] = $event;
            $this->order[] = 'group:'.$event->group;
        });
        Event::listen(PermissionEvaluated::class, function (PermissionEvaluated $event): void {
            $this->permissions[] = $event;
            $this->order[] = 'permission:'.$event->permission;
        });
    }

    public function test_group_event_contains_the_returned_result_and_original_context(): void
    {
        $this->group();
        $result = Rulecraft::group('approval')->evaluate($this->context);
        $this->assertCount(1, $this->groups);
        $event = $this->groups[0];
        $this->assertSame('approval', $event->group);
        $this->assertSame($result, $event->result);
        $this->assertSame($this->context, $event->context);
        $this->assertTrue($event->result->passed());
        $this->assertSame([], $this->permissions);
    }

    public function test_nested_results_produce_one_event_for_the_selected_group(): void
    {
        $root = $this->group('approval', ['true']);
        $child = $root->groups()->create(['name' => 'child', 'match' => 'any']);
        $child->rules()->create(['name' => 'condition', 'expression' => 'true']);
        $result = Rulecraft::group('approval')->evaluate([]);
        $this->assertSame(['group:approval'], $this->order);
        $this->assertTrue($this->groups[0]->result->groups()->get('child')->passed());
        $this->assertSame($result, $this->groups[0]->result);

        $selected = Rulecraft::group('child')->evaluate([]);
        $this->assertCount(2, $this->groups);
        $this->assertSame('child', $this->groups[1]->group);
        $this->assertSame($selected, $this->groups[1]->result);
    }

    public function test_denials_and_empty_groups_still_emit_completed_results(): void
    {
        $this->group('denied', ['false']);
        $this->group('empty', []);
        foreach (['denied', 'empty'] as $name) {
            $result = Rulecraft::group($name)->evaluate([]);
            $this->assertTrue($result->failed());
            $this->assertFalse($result->hasErrors());
        }
        $this->assertSame(['group:denied', 'group:empty'], $this->order);
    }

    public function test_recorded_expression_errors_are_available_to_listeners(): void
    {
        $this->group('approval', ['true', 'missing > 0'], 'any');
        $result = Rulecraft::group('approval')->evaluate([]);
        $this->assertCount(1, $this->groups);
        $this->assertSame($result, $this->groups[0]->result);
        $this->assertTrue($this->groups[0]->result->failed());
        $this->assertTrue($this->groups[0]->result->hasErrors());
        $this->assertCount(2, $this->groups[0]->result->rules());
    }

    public function test_context_validation_errors_emit_without_evaluating_expressions(): void
    {
        $this->group();
        config(['rulecraft.context_rules' => ['approval' => ['amount' => 'required|numeric']]]);
        $context = ['amount' => 'invalid'];
        $result = Rulecraft::group('approval')->evaluate($context);
        $this->assertCount(1, $this->groups);
        $this->assertSame($context, $this->groups[0]->context);
        $this->assertSame($result, $this->groups[0]->result);
        $this->assertArrayHasKey('amount', $this->groups[0]->result->validationErrors());
        $this->assertCount(0, $this->groups[0]->result->rules());
    }

    public function test_disabled_roots_and_ancestors_emit_denied_snapshots(): void
    {
        $root = $this->group('approval', ['true']);
        $root->groups()->create(['name' => 'child', 'match' => 'all']);
        $root->update(['enabled' => false]);
        foreach (['approval', 'child'] as $name) {
            $result = Rulecraft::group($name)->evaluate([]);
            $this->assertTrue($result->disabled());
        }
        $this->assertCount(2, $this->groups);
        $this->assertTrue($this->groups[0]->result->disabled());
        $this->assertTrue($this->groups[1]->result->disabled());
        $this->assertCount(0, $this->groups[0]->result->allRules());
        $this->assertSame('child', $this->groups[1]->group);
    }

    public function test_fail_fast_events_contain_only_visited_results(): void
    {
        $this->group('approval', ['false', 'missing > 0']);
        $result = Rulecraft::group('approval')->stopOnFailure()->evaluate([]);
        $this->assertSame($result, $this->groups[0]->result);
        $this->assertCount(1, $this->groups[0]->result->rules());
        $this->assertFalse($this->groups[0]->result->hasErrors());
        $this->assertCount(1, $this->groups);
    }

    public function test_run_dispatches_before_resolving_and_executing_handlers(): void
    {
        $group = $this->group();
        $group->rules()->first()->update([
            'action' => 'record', 'action_parameters' => ['output' => ['recorded' => true]],
        ]);
        $handler = new RecordingAction();
        $this->app->bind(RecordingAction::class, function () use ($handler): RecordingAction {
            $this->order[] = 'resolve-handler';
            $this->assertSame(['group:approval', 'resolve-handler'], $this->order);

            return $handler;
        });
        Rulecraft::registerAction('record', RecordingAction::class);
        $execution = Rulecraft::group('approval')->run($this->context);
        $this->assertTrue($execution->successful());
        $this->assertSame($execution->evaluation(), $this->groups[0]->result);
        $this->assertSame($this->context, $this->groups[0]->context);
        $this->assertTrue($execution->context()['recorded']);
        $this->assertCount(1, $handler->calls);
        $this->assertCount(1, $this->groups);
    }

    public function test_action_failure_does_not_emit_another_condition_event(): void
    {
        $group = $this->group();
        $group->rules()->first()->update([
            'action' => 'record', 'action_parameters' => ['failure' => 'Unavailable'],
        ]);
        Rulecraft::registerAction('record', RecordingAction::class);
        $execution = Rulecraft::group('approval')->run($this->context);
        $this->assertTrue($execution->failed());
        $this->assertTrue($this->groups[0]->result->passed());
        $this->assertSame($execution->evaluation(), $this->groups[0]->result);
        $this->assertCount(1, $this->groups);
    }

    public function test_listener_return_values_and_array_edits_do_not_change_the_decision(): void
    {
        $group = $this->group();
        Event::listen(GroupEvaluated::class, function (GroupEvaluated $event): bool {
            $event->context['amount'] = 999;
            $event->context['customer']['country'] = 'TH';

            return false;
        });
        $result = Rulecraft::group('approval')->run($this->context);
        $this->assertTrue($result->successful());
        $this->assertSame($this->context, $result->context());
        $this->assertSame(100, $this->context['amount']);
        $this->assertSame('MM', $this->context['customer']['country']);
        $this->assertSame($group->getKey(), $this->groups[0]->result->group()->getKey());
    }

    public function test_listener_exception_stops_run_before_action_execution(): void
    {
        $group = $this->group();
        $group->rules()->first()->update(['action' => 'record']);
        $handler = new RecordingAction();
        $this->app->instance(RecordingAction::class, $handler);
        Rulecraft::registerAction('record', RecordingAction::class);
        $failure = new RuntimeException('Listener failed');
        Event::listen(GroupEvaluated::class, function () use ($failure): void {
            throw $failure;
        });
        try {
            Rulecraft::group('approval')->run($this->context);
            $this->fail('Expected listener failure to propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame([], $handler->calls);
        $this->assertCount(1, $this->groups);
    }

    public function test_incomplete_group_evaluations_do_not_emit_completion_events(): void
    {
        try {
            Rulecraft::group('missing')->evaluate([]);
            $this->fail('Expected missing group failure.');
        } catch (RuleGroupNotFoundException $error) {
            $this->assertSame([], $this->groups);
        }
        $this->group('any', ['true'], 'any');
        try {
            Rulecraft::group('any')->stopOnFailure()->evaluate([]);
            $this->fail('Expected invalid fail-fast selection.');
        } catch (InvalidArgumentException $error) {
            $this->assertSame([], $this->groups);
        }
        $root = $this->group('root', ['true']);
        $root->groups()->create(['name' => 'child']);
        config(['rulecraft.max_depth' => 1]);
        try {
            Rulecraft::group('root')->evaluate([]);
            $this->fail('Expected invalid hierarchy.');
        } catch (InvalidGroupHierarchyException $error) {
            $this->assertSame([], $this->groups);
        }
    }

    public function test_validation_and_transfer_do_not_emit_evaluation_events(): void
    {
        $group = $this->group();
        Rulecraft::validateExpression('amount <= limit', ['amount', 'limit']);
        $json = Rulecraft::export('approval');
        $group->delete();
        Rulecraft::import($json);
        $this->assertSame([], $this->groups);
        $this->assertSame([], $this->permissions);
    }

    public function test_permission_events_include_selection_metadata_without_fluent_leaks(): void
    {
        $user = $this->grant($this->group(), 'api');
        $pending = Rulecraft::for($user)->permission('loan-approval');
        $default = $pending->evaluate($this->context);
        $explicit = $pending->guard('api')->evaluate($this->context);
        $again = $pending->evaluate($this->context);
        $this->assertTrue($default->failed());
        $this->assertTrue($explicit->passed());
        $this->assertTrue($again->failed());
        $this->assertCount(3, $this->permissions);
        $this->assertNull($this->permissions[0]->guard);
        $event = $this->permissions[1];
        $this->assertSame($user, $event->user);
        $this->assertSame('loan-approval', $event->permission);
        $this->assertSame('api', $event->guard);
        $this->assertSame($this->context, $event->context);
        $this->assertSame($explicit, $event->result);
        $this->assertSame($explicit->evaluations()->first(), $this->groups[0]->result);
        $this->assertNull($this->permissions[2]->guard);
        $this->assertSame([
            'permission:loan-approval', 'group:approval',
            'permission:loan-approval', 'permission:loan-approval',
        ], $this->order);
    }

    public function test_no_grants_or_mappings_still_emit_a_denied_permission_event(): void
    {
        $this->installPermissions();
        $user = User::create(['name' => 'Manager']);
        $pending = Rulecraft::for($user)->permission('loan-approval');
        $first = $pending->evaluate([]);
        $permission = Permission::create(['name' => 'loan-approval', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $second = $pending->evaluate([]);
        $this->assertCount(2, $this->permissions);
        $this->assertSame($first, $this->permissions[0]->result);
        $this->assertSame($second, $this->permissions[1]->result);
        $this->assertTrue($first->failed());
        $this->assertTrue($second->failed());
        $this->assertSame([], $this->groups);
    }

    public function test_permission_event_follows_all_grants_and_contains_aggregated_errors(): void
    {
        $broken = $this->group('broken', ['missing > 0']);
        $user = $this->grant($broken);
        $role = Role::create(['name' => 'Head Office', 'guard_name' => 'web']);
        $permission = Permission::findByName('loan-approval', 'web');
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $passing = $this->group('passing', ['true']);
        $passing->rules()->first()->update(['action' => 'unregistered']);
        RolePermissionRule::assign($role, $permission, $passing);
        $result = Rulecraft::for($user)->permission('loan-approval')->evaluate([]);
        $this->assertTrue($result->passed());
        $this->assertTrue($result->hasErrors());
        $this->assertCount(2, $this->groups);
        $this->assertCount(1, $this->permissions);
        $this->assertSame($result, $this->permissions[0]->result);
        $this->assertCount(2, $this->permissions[0]->result->evaluations());
        $this->assertSame('permission:loan-approval', end($this->order));
    }

    public function test_invalid_mappings_emit_permission_events_with_recorded_errors(): void
    {
        $group = $this->group('approval', ['true']);
        $user = $this->grant($group);
        $parent = $this->group('parent', ['false']);
        RuleGroup::query()->whereKey($group->getKey())->update(['parent_id' => $parent->getKey()]);
        $result = Rulecraft::for($user)->permission('loan-approval')->evaluate([]);
        $this->assertTrue($result->failed());
        $this->assertTrue($result->hasErrors());
        $this->assertSame([], $this->groups);
        $this->assertCount(1, $this->permissions);
        $this->assertSame($result, $this->permissions[0]->result);
    }

    public function test_permission_listener_exceptions_follow_completed_group_events(): void
    {
        $user = $this->grant($this->group());
        $failure = new RuntimeException('Permission listener failed');
        Event::listen(PermissionEvaluated::class, function () use ($failure): void {
            throw $failure;
        });
        try {
            Rulecraft::for($user)->permission('loan-approval')->evaluate($this->context);
            $this->fail('Expected permission listener failure.');
        } catch (RuntimeException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame(['group:approval', 'permission:loan-approval'], $this->order);
    }

    public function test_invalid_selection_or_resolver_failure_emits_no_permission_event(): void
    {
        $this->app->instance(GrantResolver::class, new class() implements GrantResolver
        {
            public function grants($user, string $permission, ?string $guard = null): iterable
            {
                throw new RuntimeException('Resolver failed');
            }
        });
        $pending = Rulecraft::for(null);
        try {
            $pending->evaluate([]);
            $this->fail('Expected a missing permission selection.');
        } catch (InvalidArgumentException $error) {
            $this->assertSame([], $this->permissions);
        }
        try {
            $pending->permission('loan-approval')->evaluate([]);
            $this->fail('Expected a resolver failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Resolver failed', $error->getMessage());
        }
        $this->assertSame([], $this->permissions);
        $this->assertSame([], $this->groups);
    }

    public function test_cli_and_blade_evaluate_while_guest_permission_checks_skip_events(): void
    {
        $this->group();
        $this->withoutMockingConsoleOutput();
        $this->assertSame(0, Artisan::call('rulecraft:test', [
            'group' => 'approval', '--data' => '{"amount":100,"limit":200}',
        ]));
        $context = $this->context;
        $template = '@rulecraft("approval", $context)allowed @endrulecraft '
            .'@canRulecraft("loan-approval", $context)private @endcanRulecraft';
        $compiled = $this->app->make('blade.compiler')->compileString($template);
        ob_start();
        try {
            eval('?>'.$compiled);
            $this->assertSame('allowed', trim(ob_get_contents()));
        } finally {
            ob_end_clean();
        }
        $this->assertCount(2, $this->groups);
        $this->assertSame([], $this->permissions);
    }

    private function grant(RuleGroup $group, string $guard = 'web'): User
    {
        $this->installPermissions();
        $user = User::create(['name' => 'Manager']);
        $permission = Permission::create(['name' => 'loan-approval', 'guard_name' => $guard]);
        $role = Role::create(['name' => 'Branch Manager', 'guard_name' => $guard]);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        RolePermissionRule::assign($role, $permission, $group);

        return $user;
    }
}
