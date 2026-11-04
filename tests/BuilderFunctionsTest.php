<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use InvalidArgumentException;
use stdClass;
use Thuraaung\Rulecraft\Builder\DefinitionCompiler;
use Thuraaung\Rulecraft\Builder\FunctionCatalog;
use Thuraaung\Rulecraft\Builder\RuleGroupBuilder;
use Thuraaung\Rulecraft\Exceptions\InvalidPolicyException;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Tests\Fixtures\BuilderData;
use Thuraaung\Rulecraft\Tests\Fixtures\BuilderFunctions;
use Thuraaung\Rulecraft\Tests\Fixtures\CustomerFunctions;

class BuilderFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['rulecraft.expression_providers' => [BuilderFunctions::class]]);
        BuilderFunctions::$calls = 0;
    }

    public function test_function_preview_executes_provider_but_validation_and_save_do_not(): void
    {
        $builder = $this->app->make(RuleGroupBuilder::class);
        $draft = $this->draft();
        $validated = $builder->validate($draft);
        $this->assertSame('credit_score(customer_id) >= 650', $validated->rules[0]->expression);
        $builder->save($draft);
        $this->assertSame(0, BuilderFunctions::$calls);
        $this->assertNotNull($builder->load('approval')['group']->rules[0]->builder_definition);
        $this->assertTrue($builder->preview($draft, ['customer_id' => 1,
            'scores' => [1 => 700]])->passed());
        $this->assertFalse($builder->preview($draft, ['customer_id' => 1,
            'scores' => [1 => 600]])->passed());
        $this->assertSame(2, BuilderFunctions::$calls);
    }

    public function test_typed_literal_arguments_and_boolean_results_round_trip(): void
    {
        $builder = $this->app->make(RuleGroupBuilder::class);
        $draft = $this->draft();
        $draft->rules[0]->builder_definition = $this->definition('eligible', 'boolean', [
            'score' => ['type' => 'value', 'value_type' => 'integer', 'value' => '700'],
        ], '===', ['type' => 'value', 'value_type' => 'boolean', 'value' => true]);
        $builder->save($draft);
        $loaded = $builder->load('approval')['group'];
        $this->assertSame('eligible(700) === true', $loaded->rules[0]->expression);
        $this->assertTrue($builder->preview($loaded, [])->passed());
        $arguments = $loaded->rules[0]->builder_definition->source['arguments'];
        $this->assertSame('700', $arguments['score']['value']);
    }

    public function test_zero_argument_functions_and_context_comparisons_work(): void
    {
        $draft = $this->draft();
        $draft->rules[0]->builder_definition = $this->definition('current_limit', 'number', [],
            '<=', ['type' => 'field', 'field' => 'requested_limit']);
        $builder = $this->app->make(RuleGroupBuilder::class);
        $this->assertSame('current_limit() <= requested_limit',
            $builder->validate($draft)->rules[0]->expression);
        $this->assertTrue($builder->preview($draft, ['limit' => 100,
            'requested_limit' => 200])->passed());
    }

    public function test_free_fields_preserve_decimal_precision_without_a_catalog(): void
    {
        $draft = $this->draft();
        $draft->rules[0]->builder_definition = (object) [
            'source' => ['type' => 'field', 'field' => 'loan.amount', 'value_type' => 'number'],
            'operator' => '===', 'operand' => ['type' => 'value',
                'value_type' => 'number', 'value' => '1.2345678901234567']];
        $builder = $this->app->make(RuleGroupBuilder::class);
        $builder->save($draft);
        $loaded = $builder->load('approval')['group'];
        $this->assertSame('loan["amount"] === 1.2345678901234567', $loaded->rules[0]->expression);
        $this->assertTrue($builder->preview($loaded, ['loan' => [
            'amount' => 1.2345678901234567]])->passed());
    }

    public function test_transfer_preserves_function_definitions_without_builder_metadata(): void
    {
        $builder = $this->app->make(RuleGroupBuilder::class);
        $builder->save($this->draft());
        $json = Rulecraft::export('approval');
        $document = json_decode($json);
        $this->assertSame(2, $document->version);
        $definition = $document->group->rules[0]->builder_definition;
        $this->assertFalse(property_exists($definition, 'version'));
        RuleGroup::query()->delete();
        $this->app->instance(BuilderFunctions::class, new class() extends BuilderFunctions
        {
            public function getBuilderFunctions(): array
            {
                return [];
            }
        });
        $imported = Rulecraft::import($json);
        $this->assertNotNull($imported->rules()->first()->builder_definition);
        $this->assertNull($builder->load('approval')['group']->rules[0]->builder_definition);
        $this->assertSame(0, BuilderFunctions::$calls);
    }

    public function test_unknown_functions_wrong_signatures_and_injections_are_rejected(): void
    {
        $valid = json_decode(json_encode($this->draft()), true);
        $mutations = [
            function (&$source) {
                $source['name'] = 'not_registered';
            },
            function (&$source) {
                $source['name'] = 'eligible) or true';
            },
            function (&$source) {
                $source['arguments'] = [];
            },
            function (&$source) {
                $source['arguments']['extra'] = ['type' => 'field',
                    'field' => 'extra'];
            },
            function (&$source) {
                $source['return_type'] = 'boolean';
            },
            function (&$source) {
                $source['arguments']['customer_id'] = ['type' => 'field',
                    'field' => 'id) or true'];
            },
            function (&$source) {
                $source['arguments']['customer_id'] = ['type' => 'value',
                    'value_type' => 'string', 'value' => '1'];
            },
        ];
        foreach ($mutations as $mutate) {
            $copy = $valid;
            $mutate($copy['rules'][0]['builder_definition']['source']);
            try {
                $this->app->make(RuleGroupBuilder::class)->save(json_decode(json_encode($copy)));
                $this->fail('Malformed function definition was accepted.');
            } catch (InvalidPolicyException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
        $this->assertSame(0, RuleGroup::count());
        $this->assertSame(0, BuilderFunctions::$calls);
    }

    public function test_function_return_types_restrict_operators_and_literal_values(): void
    {
        $compiler = $this->app->make(DefinitionCompiler::class);
        foreach (['<', '=='] as $operator) {
            $definition = $this->definition('eligible', 'boolean', [
                'score' => ['type' => 'field', 'field' => 'score'],
            ], $operator, ['type' => 'value', 'value_type' => 'integer', 'value' => '1']);
            try {
                $compiler->compile(json_decode(json_encode($definition), true));
                $this->fail('Invalid boolean function comparison was accepted.');
            } catch (InvalidPolicyException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }

    public function test_removed_provider_falls_back_to_code_without_losing_expression(): void
    {
        $builder = $this->app->make(RuleGroupBuilder::class);
        $builder->save($this->draft());
        config(['rulecraft.expression_providers' => []]);
        $loaded = $builder->load('approval')['group']->rules[0];
        $this->assertNull($loaded->builder_definition);
        $this->assertSame('credit_score(customer_id) >= 650', $loaded->expression);
        $this->assertNotNull(RuleGroup::first()->rules()->first()->builder_definition);
    }

    public function test_multiple_arguments_preserve_order_decimal_types_and_large_integers(): void
    {
        $draft = $this->draft();
        $draft->rules[0]->builder_definition = $this->definition('adjust_score', 'number', [
            'score' => ['type' => 'value', 'value_type' => 'integer',
                'value' => '9007199254740993'],
            'bonus' => ['type' => 'value', 'value_type' => 'number', 'value' => '1.0'],
        ], '>=', ['type' => 'value', 'value_type' => 'integer', 'value' => '650']);
        $builder = $this->app->make(RuleGroupBuilder::class);
        $builder->save($draft);
        $loaded = $builder->load('approval')['group'];
        $this->assertSame('adjust_score(9007199254740993, 1.0) >= 650',
            $loaded->rules[0]->expression);
        $this->assertTrue($builder->preview($loaded, [])->passed());
        $definition = $draft->rules[0]->builder_definition;
        $definition->source['arguments'] = array_reverse($definition->source['arguments'], true);
        $this->expectException(InvalidPolicyException::class);
        $builder->validate($draft);
    }

    public function test_changed_function_signature_falls_back_to_expression_mode(): void
    {
        $builder = $this->app->make(RuleGroupBuilder::class);
        $builder->save($this->draft());
        $this->app->instance(BuilderFunctions::class, new class() extends BuilderFunctions
        {
            public function getBuilderFunctions(): array
            {
                $functions = parent::getBuilderFunctions();
                $functions['credit_score']['arguments'] = [
                    'id' => ['label' => 'ID', 'type' => 'integer'],
                ];

                return $functions;
            }
        });
        $loaded = $builder->load('approval')['group']->rules[0];
        $this->assertNull($loaded->builder_definition);
        $this->assertSame('credit_score(customer_id) >= 650', $loaded->expression);
    }

    public function test_standard_providers_remain_expression_only(): void
    {
        config(['rulecraft.expression_providers' => [CustomerFunctions::class]]);
        $this->assertSame([], $this->app->make(FunctionCatalog::class)->all());
    }

    public function test_builder_metadata_cannot_expose_unregistered_functions(): void
    {
        $this->app->instance(BuilderFunctions::class, new class() extends BuilderFunctions
        {
            public function getBuilderFunctions(): array
            {
                return ['not_registered' => ['label' => 'Invalid', 'arguments' => [],
                    'return_type' => 'number']];
            }
        });
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(FunctionCatalog::class)->all();
    }

    public function test_function_comparisons_round_trip_and_evaluate_both_sides(): void
    {
        $builder = $this->app->make(RuleGroupBuilder::class);
        $draft = $this->draft();
        $definition = $draft->rules[0]->builder_definition;
        $definition->operator = '==';
        $definition->operand = $definition->source;
        $definition->operand['arguments']['customer_id']['field'] = 'guarantor_id';
        $builder->save($draft);
        $loaded = $builder->load('approval')['group'];
        $this->assertSame('credit_score(customer_id) == credit_score(guarantor_id)',
            $loaded->rules[0]->expression);
        $this->assertSame(0, BuilderFunctions::$calls);
        $context = ['customer_id' => 1, 'guarantor_id' => 2, 'scores' => [1 => 700, 2 => 700]];
        $this->assertTrue($builder->preview($loaded, $context)->passed());
        $context['scores'][2] = 600;
        $this->assertFalse($builder->preview($loaded, $context)->passed());
        $this->assertSame(4, BuilderFunctions::$calls);
        $json = Rulecraft::export('approval');
        RuleGroup::query()->delete();
        Rulecraft::import($json);
        $this->assertSame($json, Rulecraft::export('approval'));
        $this->assertFalse(Rulecraft::group('approval')->evaluate($context)->passed());
    }

    public function test_fields_compare_with_zero_argument_functions(): void
    {
        $draft = $this->draft();
        $draft->rules[0]->builder_definition = (object) [
            'source' => ['type' => 'field', 'field' => 'amount', 'value_type' => 'number'],
            'operator' => '<=', 'operand' => ['type' => 'function', 'name' => 'current_limit',
                'return_type' => 'number', 'arguments' => []],
        ];
        $builder = $this->app->make(RuleGroupBuilder::class);
        $this->assertSame('amount <= current_limit()',
            $builder->validate($draft)->rules[0]->expression);
        $this->assertTrue($builder->preview($draft, ['amount' => 100, 'limit' => 200])->passed());
        $this->assertFalse($builder->preview($draft, ['amount' => 300, 'limit' => 200])->passed());
    }

    public function test_different_functions_preserve_literal_types_and_strict_equality(): void
    {
        $draft = $this->draft();
        $definition = $draft->rules[0]->builder_definition;
        $definition->operator = '==';
        $definition->operand = ['type' => 'function', 'name' => 'adjust_score',
            'return_type' => 'number', 'arguments' => [
                'score' => ['type' => 'value', 'value_type' => 'integer', 'value' => '650'],
                'bonus' => ['type' => 'value', 'value_type' => 'number', 'value' => '50.0'],
            ]];
        $builder = $this->app->make(RuleGroupBuilder::class);
        $builder->save($draft);
        $loaded = $builder->load('approval')['group'];
        $this->assertSame('credit_score(customer_id) == adjust_score(650, 50.0)',
            $loaded->rules[0]->expression);
        $context = ['customer_id' => 1, 'scores' => [1 => 700]];
        $this->assertTrue($builder->preview($loaded, $context)->passed());
        $loaded->rules[0]->builder_definition->operator = '===';
        $this->assertFalse($builder->preview($loaded, $context)->passed());
    }

    public function test_boolean_functions_can_be_compared(): void
    {
        $draft = $this->draft();
        $source = ['type' => 'function', 'name' => 'eligible', 'return_type' => 'boolean',
            'arguments' => ['score' => ['type' => 'field', 'field' => 'score']]];
        $operand = $source;
        $operand['arguments']['score']['field'] = 'guarantor_score';
        $draft->rules[0]->builder_definition = (object) ['source' => $source,
            'operator' => '===', 'operand' => $operand];
        $builder = $this->app->make(RuleGroupBuilder::class);
        $this->assertTrue($builder->preview($draft, ['score' => 700,
            'guarantor_score' => 700])->passed());
        $this->assertFalse($builder->preview($draft, ['score' => 700,
            'guarantor_score' => 600])->passed());
    }

    public function test_invalid_comparison_functions_are_rejected_before_any_writes(): void
    {
        $invalid = [
            ['type' => 'function', 'name' => 'unknown', 'return_type' => 'number',
                'arguments' => []],
            ['type' => 'function', 'name' => 'credit_score', 'return_type' => 'number',
                'arguments' => []],
            ['type' => 'function', 'name' => 'eligible', 'return_type' => 'boolean',
                'arguments' => ['score' => ['type' => 'field', 'field' => 'score']]],
            ['type' => 'function', 'name' => 'credit_score', 'return_type' => 'number',
                'arguments' => ['customer_id' => ['type' => 'field', 'field' => 'id) or true']]],
        ];
        foreach ($invalid as $operand) {
            $draft = $this->draft();
            $draft->rules[0]->builder_definition->operand = $operand;
            try {
                $this->app->make(RuleGroupBuilder::class)->save($draft);
                $this->fail('Invalid comparison function was accepted.');
            } catch (InvalidPolicyException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
        $this->assertSame(0, RuleGroup::count());
        $this->assertSame(0, BuilderFunctions::$calls);
    }

    public function test_scalar_functions_cannot_replace_a_membership_list(): void
    {
        $draft = $this->draft();
        $draft->rules[0]->builder_definition->operator = 'in';
        $draft->rules[0]->builder_definition->operand = ['type' => 'function',
            'name' => 'current_limit', 'return_type' => 'number', 'arguments' => []];
        $this->expectException(InvalidPolicyException::class);
        $this->app->make(RuleGroupBuilder::class)->validate($draft);
    }

    public function test_missing_compared_function_metadata_falls_back_to_expression(): void
    {
        $draft = $this->draft();
        $draft->rules[0]->builder_definition->operand = ['type' => 'function',
            'name' => 'current_limit', 'return_type' => 'number', 'arguments' => []];
        $builder = $this->app->make(RuleGroupBuilder::class);
        $builder->save($draft);
        $this->app->instance(BuilderFunctions::class, new class() extends BuilderFunctions
        {
            public function getBuilderFunctions(): array
            {
                $functions = parent::getBuilderFunctions();
                unset($functions['current_limit']);

                return $functions;
            }
        });
        $loaded = $builder->load('approval')['group']->rules[0];
        $this->assertNull($loaded->builder_definition);
        $this->assertSame(
            'credit_score(customer_id) >= current_limit()', $loaded->expression
        );
        $this->assertNotNull(RuleGroup::first()->rules()->first()->builder_definition);
    }

    private function draft(): stdClass
    {
        $draft = BuilderData::group();
        $draft->rules[0]->builder_definition = $this->definition('credit_score', 'number', [
            'customer_id' => ['type' => 'field', 'field' => 'customer_id'],
        ], '>=', ['type' => 'value', 'value_type' => 'integer', 'value' => '650']);

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $operand
     */
    private function definition(
        string $name,
        string $type,
        array $arguments,
        string $operator,
        array $operand
    ): stdClass {
        return (object) ['source' => ['type' => 'function', 'name' => $name,
            'arguments' => $arguments, 'return_type' => $type],
            'operator' => $operator, 'operand' => $operand];
    }
}
