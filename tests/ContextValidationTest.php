<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RolePermissionRule;
use Thuraaung\Rulecraft\Tests\Fixtures\CustomerFunctions;
use Thuraaung\Rulecraft\Tests\Fixtures\User;

class ContextValidationTest extends TestCase
{
    public function test_missing_schema_preserves_existing_evaluation_behavior(): void
    {
        $this->group();
        config(['rulecraft.context_rules' => ['other-group' => ['required_value' => 'required']]]);
        $result = Rulecraft::group('approval')->evaluate(['amount' => '100', 'limit' => 200]);

        $this->assertTrue($result->passed());
        $this->assertSame([], $result->validationErrors());
        $this->assertFalse($result->hasErrors());
    }

    public function test_missing_fields_are_reported_before_expressions_and_actions(): void
    {
        $group = $this->group('approval', ['eligible(score)']);
        $group->rules()->first()->update(['action' => 'unregistered']);
        $group->groups()->create(['name' => 'child']);
        config([
            'rulecraft.expression_providers' => [CustomerFunctions::class],
            'rulecraft.context_rules' => ['approval' => ['amount' => ['required', 'numeric']]],
        ]);
        CustomerFunctions::$calls = 0;
        Rulecraft::clearResolvedInstances();

        $execution = Rulecraft::group('approval')->run(['score' => 700]);
        $result = $execution->evaluation();

        $this->assertTrue($execution->failed());
        $this->assertCount(0, $execution->actions());
        $this->assertCount(0, $result->allRules());
        $this->assertCount(0, $result->groups());
        $this->assertCount(0, $result->eligibleRules());
        $this->assertArrayHasKey('amount', $result->validationErrors());
        $this->assertNotEmpty($result->validationErrors()['amount']);
        $this->assertTrue($result->hasErrors());
        $this->assertCount(1, $result->errors());
        $this->assertInstanceOf(ValidationException::class, $result->errors()->first());
        $this->assertSame(0, CustomerFunctions::$calls);
    }

    public function test_type_failures_report_each_field(): void
    {
        $this->group('approval', ['true']);
        config(['rulecraft.context_rules' => ['approval' => [
            'amount' => 'required|numeric',
            'count' => 'required|integer',
            'active' => 'required|boolean',
            'name' => 'required|string',
            'tags' => 'required|array',
        ]]]);

        $result = Rulecraft::group('approval')->evaluate([
            'amount' => 'not-a-number',
            'count' => 1.5,
            'active' => 'yes',
            'name' => ['unexpected'],
            'tags' => 'unexpected',
        ]);

        $this->assertTrue($result->failed());
        $this->assertSame(
            ['amount', 'count', 'active', 'name', 'tags'],
            array_keys($result->validationErrors())
        );
    }

    public function test_nested_and_wildcard_rules_support_arrays(): void
    {
        $this->group('approval', ['customer["country"] == "MM"']);
        config(['rulecraft.context_rules' => ['approval' => [
            'customer' => 'required|array',
            'customer.country' => 'required|string',
            'items' => 'required|array',
            'items.*.quantity' => 'required|integer|min:1',
        ]]]);
        $valid = ['customer' => ['country' => 'MM'], 'items' => [['quantity' => 2]]];
        $this->assertTrue(Rulecraft::group('approval')->evaluate($valid)->passed());

        $invalid = ['customer' => [], 'items' => [['quantity' => 0], []]];
        $errors = Rulecraft::group('approval')->evaluate($invalid)->validationErrors();
        $this->assertArrayHasKey('customer.country', $errors);
        $this->assertArrayHasKey('items.0.quantity', $errors);
        $this->assertArrayHasKey('items.1.quantity', $errors);
    }

    public function test_optional_and_nullable_fields_do_not_require_a_value(): void
    {
        $this->group('approval', ['true']);
        config(['rulecraft.context_rules' => ['approval' => [
            'note' => 'sometimes|nullable|string',
        ]]]);
        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->passed());
        $this->assertTrue(Rulecraft::group('approval')->evaluate(['note' => null])->passed());
        $this->assertTrue(Rulecraft::group('approval')->evaluate(['note' => 12])->failed());
    }

    public function test_validation_does_not_cast_or_filter_context(): void
    {
        $this->group('approval', ['amount === "100" and extra == "preserved"']);
        config(['rulecraft.context_rules' => ['approval' => ['amount' => 'required|integer']]]);
        $context = ['amount' => '100', 'extra' => 'preserved'];
        $execution = Rulecraft::group('approval')->run($context);

        $this->assertTrue($execution->successful());
        $this->assertSame($context, $execution->context());
        $this->assertSame('100', $context['amount']);
    }

    public function test_child_validation_errors_fail_any_parents_and_are_aggregated(): void
    {
        $root = $this->group('approval', ['true'], 'any');
        $child = $root->groups()->create(['name' => 'child']);
        $child->rules()->create(['name' => 'would-pass', 'expression' => 'true']);
        config(['rulecraft.context_rules' => ['child' => ['amount' => 'required|numeric']]]);

        $result = Rulecraft::group('approval')->evaluate([]);
        $this->assertTrue($result->failed());
        $this->assertTrue($result->hasErrors());
        $this->assertCount(1, $result->errors());
        $this->assertSame([], $result->validationErrors());
        $this->assertArrayHasKey('amount', $result->groups()->get('child')->validationErrors());
        $this->assertCount(0, $result->groups()->get('child')->rules());
        $this->assertCount(1, $result->matchedRules());
        $this->assertCount(0, $result->eligibleRules());
        $this->assertArrayHasKey(
            'amount',
            Rulecraft::group('child')->evaluate([])->validationErrors()
        );
    }

    public function test_dotted_group_names_are_exact_schema_keys(): void
    {
        $this->group('loans.approval', ['true']);
        config(['rulecraft.context_rules' => [
            'loans.approval' => ['amount' => 'required'],
        ]]);
        $this->assertTrue(Rulecraft::group('loans.approval')->evaluate([])->failed());
        $this->assertTrue(
            Rulecraft::group('loans.approval')->evaluate(['amount' => 100])->passed()
        );
    }

    public function test_no_schema_does_not_suppress_expression_errors(): void
    {
        $this->group('approval', ['missing > 0']);
        $result = Rulecraft::group('approval')->evaluate([]);
        $this->assertTrue($result->hasErrors());
        $this->assertSame([], $result->validationErrors());
        $this->assertNotInstanceOf(ValidationException::class, $result->errors()->first());
    }

    public function test_an_empty_schema_disables_validation_for_that_group(): void
    {
        $this->group('approval', ['true']);
        config(['rulecraft.context_rules' => ['approval' => []]]);
        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->passed());
    }

    public function test_invalid_schema_configuration_is_not_silently_ignored(): void
    {
        $this->group('approval', ['true']);
        foreach (['invalid', ['approval' => null], ['approval' => 'required']] as $schemas) {
            config(['rulecraft.context_rules' => $schemas]);
            try {
                Rulecraft::group('approval')->evaluate([]);
                $this->fail('Expected invalid schema configuration to throw.');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('must be an array', $error->getMessage());
            }
        }
    }

    public function test_cli_displays_field_errors_and_returns_two(): void
    {
        $this->group('approval', ['true']);
        config(['rulecraft.context_rules' => ['approval' => ['amount' => 'required|numeric']]]);
        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('rulecraft:test', ['group' => 'approval', '--data' => '{}']);

        $output = Artisan::output();
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('ERROR  approval [ALL]', $output);
        $this->assertStringContainsString('ERROR  context.amount:', $output);
    }

    public function test_blade_group_condition_denies_invalid_context(): void
    {
        $this->group('approval', ['true']);
        config(['rulecraft.context_rules' => ['approval' => ['amount' => 'required']]]);
        $blade = $this->app->make('blade.compiler');

        $this->assertFalse($blade->check('rulecraft', 'approval', []));
        $this->assertTrue($blade->check('rulecraft', 'approval', ['amount' => 100]));
    }

    public function test_permission_checks_keep_errors_separate_from_independent_grants(): void
    {
        $this->installPermissions();
        $user = User::create(['name' => 'Manager']);
        $permission = Permission::create(['name' => 'approve', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'branch', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $group = $this->group('approval', ['true']);
        $mapping = RolePermissionRule::assign($role, $permission, $group);
        config(['rulecraft.context_rules' => ['approval' => ['amount' => 'required']]]);
        $this->actingAs($user);

        $pending = Rulecraft::for($user)->permission('approve');
        $denied = $pending->evaluate([]);
        $this->assertTrue($denied->failed());
        $this->assertTrue($denied->hasErrors());
        $this->assertArrayHasKey(
            'amount',
            $denied->evaluations()->get($mapping->getKey())->validationErrors()
        );
        $blade = $this->app->make('blade.compiler');
        $this->assertFalse($blade->check('canRulecraft', 'approve', []));
        $this->assertTrue($blade->check('canRulecraft', 'approve', ['amount' => 100]));

        $other = Role::create(['name' => 'head-office', 'guard_name' => 'web']);
        $other->givePermissionTo($permission);
        $user->assignRole($other);
        RolePermissionRule::assign($other, $permission, $this->group('head-office', ['true']));
        $allowed = $pending->evaluate([]);
        $this->assertTrue($allowed->passed());
        $this->assertTrue($allowed->hasErrors());
        $this->assertCount(1, $allowed->errors());
    }
}
