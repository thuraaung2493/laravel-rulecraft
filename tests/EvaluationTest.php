<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use InvalidArgumentException;
use Thuraaung\Rulecraft\Exceptions\InvalidExpressionException;
use Thuraaung\Rulecraft\Exceptions\RuleGroupNotFoundException;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Tests\Fixtures\CustomerFunctions;

class EvaluationTest extends TestCase
{
    public function test_all_conditions_must_pass(): void
    {
        $this->group('approval', ['amount <= limit', 'branch == user_branch']);
        $result = Rulecraft::group('approval')->evaluate([
            'amount' => 100, 'limit' => 200, 'branch' => 1, 'user_branch' => 2,
        ]);

        $this->assertTrue($result->failed());
        $this->assertCount(1, $result->matchedRules());
        $this->assertCount(1, $result->failedRules());
        $this->assertFalse($result->hasErrors());
    }

    public function test_any_group_evaluates_every_rule(): void
    {
        $this->group('approval', ['amount <= limit', 'amount == 0'], 'any');
        $result = Rulecraft::group('approval')->evaluate(['amount' => 100, 'limit' => 200]);

        $this->assertTrue($result->passed());
        $this->assertCount(2, $result->rules());
    }

    public function test_an_error_denies_any_group_even_when_another_rule_matches(): void
    {
        $this->group('approval', ['true', 'missing > 1'], 'any');
        $result = Rulecraft::group('approval')->evaluate([]);

        $this->assertTrue($result->failed());
        $this->assertTrue($result->hasErrors());
        $this->assertInstanceOf(\Throwable::class, $result->errors()->first());
    }

    public function test_empty_groups_fail(): void
    {
        $this->group('approval', []);
        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->failed());
    }

    public function test_missing_groups_raise_a_clear_exception(): void
    {
        $this->expectException(RuleGroupNotFoundException::class);
        Rulecraft::group('missing')->evaluate([]);
    }

    public function test_stop_on_failure_does_not_leak_into_other_evaluations(): void
    {
        $this->group('approval', ['false', 'missing > 1']);
        $pending = Rulecraft::group('approval');
        $stopped = $pending->stopOnFailure()->evaluate([]);

        $this->assertCount(1, $stopped->rules());
        $this->assertFalse($stopped->hasErrors());
        $this->assertCount(2, $pending->evaluate([])->rules());
        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->hasErrors());
    }

    public function test_stop_on_failure_is_rejected_for_any_groups(): void
    {
        $this->group('approval', ['false', 'true'], 'any');
        $this->expectException(InvalidArgumentException::class);
        Rulecraft::group('approval')->stopOnFailure()->evaluate([]);
    }

    public function test_invalid_matching_modes_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RuleGroup::create(['name' => 'invalid', 'match' => 'sometimes']);
    }

    public function test_expression_validation_does_not_execute_registered_functions(): void
    {
        config(['rulecraft.expression_providers' => [CustomerFunctions::class]]);
        CustomerFunctions::$calls = 0;
        Rulecraft::clearResolvedInstances();

        Rulecraft::validateExpression('eligible(score)', ['score']);
        $this->assertSame(0, CustomerFunctions::$calls);

        $this->group('approval', ['eligible(score)']);
        $this->assertTrue(Rulecraft::group('approval')->evaluate(['score' => 700])->passed());
        $this->assertSame(1, CustomerFunctions::$calls);
    }

    public function test_invalid_expressions_are_reported_during_validation(): void
    {
        $this->expectException(InvalidExpressionException::class);
        Rulecraft::validateExpression('unknown >= 10', ['amount']);
    }

    public function test_expression_semantics_include_nested_groups_strings_arrays_and_nulls(): void
    {
        $this->group('approval', [
            '(amount >= 100 and country in ["TH", "MM"]) or vip',
            'metadata["note"] == "customer\\"s loan"',
            'optional == null',
        ]);

        $this->assertTrue(Rulecraft::group('approval')->evaluate([
            'amount' => 150, 'country' => 'TH', 'vip' => false,
            'metadata' => ['note' => 'customer"s loan'], 'optional' => null,
        ])->passed());
    }

    public function test_service_can_be_injected_without_using_the_facade(): void
    {
        $this->group();
        $service = $this->app->make(\Thuraaung\Rulecraft\Rulecraft::class);
        $this->assertTrue(
            $service->group('approval')->evaluate(['amount' => 10, 'limit' => 20])->passed()
        );
    }
}
