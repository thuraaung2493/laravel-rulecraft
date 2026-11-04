<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use stdClass;
use Thuraaung\Rulecraft\Exceptions\InvalidPolicyException;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\Rule;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Tests\Fixtures\CustomerFunctions;
use Thuraaung\Rulecraft\Tests\Fixtures\InjectedAction;
use Thuraaung\Rulecraft\Tests\Fixtures\PolicyData;

class PolicyTransferTest extends TestCase
{
    public function test_nested_policies_round_trip_without_losing_metadata(): void
    {
        $root = $this->group('approval', [], 'any');
        $root->update(['enabled' => false]);
        $root->rules()->createMany([
            [
                'name' => 'first', 'expression' => 'amount > 0', 'enabled' => false,
                'priority' => 10, 'action' => 'unregistered',
                'action_parameters' => (object) [
                    'label' => '<info>မြန်မာ</info>', 'rate' => 1.0,
                    'empty_object' => (object) [], 'empty_list' => [],
                ],
            ],
            ['name' => 'second', 'expression' => 'true', 'priority' => 10],
        ]);
        $child = $root->groups()->create(['name' => 'child', 'match' => 'all']);
        $child->rules()->create([
            'name' => 'third', 'expression' => 'false', 'priority' => -5,
            'action_parameters' => [1, true, null, ['nested' => 'value']],
        ]);
        $json = Rulecraft::export('approval');
        $document = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $document->version);
        $this->assertFalse($document->group->enabled);
        $this->assertFalse($document->group->rules[0]->enabled);
        $this->assertSame(1, $document->group->rules[0]->action_parameters->rate);
        $this->assertInstanceOf(
            stdClass::class,
            $document->group->rules[0]->action_parameters->empty_object
        );
        $this->assertSame([], $document->group->rules[0]->action_parameters->empty_list);
        $this->assertFalse(property_exists($document->group, 'id'));
        $this->assertFalse(property_exists($document->group, 'parent_id'));
        $this->assertFalse(property_exists($document->group, 'permissionRules'));

        $oldId = $root->getKey();
        $root->delete();
        $imported = Rulecraft::import($json);
        $this->assertTrue($imported->exists);
        $this->assertNull($imported->parent_id);
        $this->assertNotSame($oldId, $imported->getKey());
        $this->assertSame(2, RuleGroup::count());
        $this->assertSame(3, Rule::count());
        $this->assertSame('first', $imported->rules()->first()->name);
        $this->assertSame($json, Rulecraft::export('approval'));
    }

    public function test_imported_policies_can_be_evaluated_without_running_handlers(): void
    {
        $document = PolicyData::document();
        $document->group->rules[0]->action = 'unregistered';
        $root = Rulecraft::import(PolicyData::json($document));
        $this->assertSame('approval', $root->name);
        $this->assertTrue(
            Rulecraft::group('approval')->evaluate(['amount' => 100, 'limit' => 200])->passed()
        );
        $this->assertTrue(
            Rulecraft::group('approval')->evaluate(['amount' => 300, 'limit' => 200])->failed()
        );
    }

    public function test_invalid_json_and_root_types_make_no_changes(): void
    {
        foreach (['{invalid', '[]', 'null', 'true', '"text"'] as $json) {
            try {
                Rulecraft::import($json);
                $this->fail('Expected invalid policy JSON to be rejected.');
            } catch (InvalidPolicyException $error) {
                $this->assertNotSame('', $error->getMessage());
                $this->assertSame(0, RuleGroup::count());
            }
        }
    }

    public function test_invalid_fields_and_unknown_keys_are_rejected_before_writes(): void
    {
        $mutations = [
            function (stdClass $doc): void {
                $doc->version = 2;
            },
            function (stdClass $doc): void {
                $doc->version = 1.0;
            },
            function (stdClass $doc): void {
                $doc->table = 'other';
            },
            function (stdClass $doc): void {
                unset($doc->group->enabled);
            },
            function (stdClass $doc): void {
                $doc->group->enabled = 'false';
            },
            function (stdClass $doc): void {
                $doc->group->match = 'or';
            },
            function (stdClass $doc): void {
                $doc->group->name = ' ';
            },
            function (stdClass $doc): void {
                $doc->group->name = str_repeat('x', 192);
            },
            function (stdClass $doc): void {
                $doc->group->rules = (object) [];
            },
            function (stdClass $doc): void {
                $doc->group->groups = [42];
            },
            function (stdClass $doc): void {
                unset($doc->group->rules[0]->action);
            },
            function (stdClass $doc): void {
                $doc->group->rules[0]->enabled = 0;
            },
            function (stdClass $doc): void {
                $doc->group->rules[0]->priority = '0';
            },
            function (stdClass $doc): void {
                $doc->group->rules[0]->priority = 2147483648;
            },
            function (stdClass $doc): void {
                $doc->group->rules[0]->action = (object) [];
            },
            function (stdClass $doc): void {
                $doc->group->rules[0]->action_parameters = 42;
            },
            function (stdClass $doc): void {
                $doc->group->rules[0]->handler = 'Unknown';
            },
        ];
        $created = 0;
        RuleGroup::creating(function () use (&$created): void {
            $created++;
        });

        foreach ($mutations as $mutate) {
            $document = PolicyData::document();
            $mutate($document);
            try {
                Rulecraft::import(PolicyData::json($document));
                $this->fail('Expected invalid policy fields to be rejected.');
            } catch (InvalidPolicyException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }

        $this->assertSame(0, $created);
        $this->assertSame(0, RuleGroup::count());
        $this->assertSame(0, Rule::count());
    }

    public function test_invalid_descendant_expressions_are_rejected_before_saving(): void
    {
        $document = PolicyData::document();
        $child = PolicyData::document('child')->group;
        $child->rules[0]->expression = 'amount >';
        $document->group->groups[] = $child;
        $created = 0;
        RuleGroup::creating(function () use (&$created): void {
            $created++;
        });

        try {
            Rulecraft::import(PolicyData::json($document));
            $this->fail('Expected invalid expression syntax to fail.');
        } catch (InvalidPolicyException $error) {
            $this->assertStringContainsString(
                'group.groups.0.rules.0.expression',
                $error->getMessage()
            );
            $this->assertNotNull($error->getPrevious());
        }
        $this->assertSame(0, $created);
        $this->assertSame(0, RuleGroup::count());
    }

    public function test_duplicate_group_names_inside_a_document_are_rejected(): void
    {
        $document = PolicyData::document();
        $document->group->groups[] = PolicyData::document('approval')->group;
        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage('Duplicate group name [approval]');
        Rulecraft::import(PolicyData::json($document));
    }

    public function test_existing_child_names_cannot_be_replaced_by_import(): void
    {
        $existing = $this->group('existing', ['true']);
        $document = PolicyData::document();
        $document->group->groups[] = PolicyData::document('existing')->group;
        try {
            Rulecraft::import(PolicyData::json($document));
            $this->fail('Expected a conflicting name to be rejected.');
        } catch (InvalidPolicyException $error) {
            $this->assertStringContainsString('already exists', $error->getMessage());
        }
        $this->assertSame(1, RuleGroup::count());
        $this->assertSame(1, Rule::count());
        $this->assertSame('condition-0', $existing->rules()->first()->name);
    }

    public function test_import_depth_respects_the_configured_limit(): void
    {
        config(['rulecraft.max_depth' => 2]);
        $document = PolicyData::document();
        $child = PolicyData::document('child')->group;
        $child->groups[] = PolicyData::document('grandchild')->group;
        $document->group->groups[] = $child;
        try {
            Rulecraft::import(PolicyData::json($document));
            $this->fail('Expected excessive depth to be rejected.');
        } catch (InvalidPolicyException $error) {
            $this->assertStringContainsString('max_depth', $error->getMessage());
        }
        $this->assertSame(0, RuleGroup::count());
    }

    public function test_export_requires_a_complete_root_policy(): void
    {
        $root = $this->group('root', ['true']);
        $root->groups()->create(['name' => 'child']);
        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage('Export a root group');
        Rulecraft::export('child');
    }

    public function test_model_save_cancellation_rolls_back_the_entire_import(): void
    {
        $document = PolicyData::document();
        $second = PolicyData::rule();
        $second->name = 'cancel';
        $document->group->rules[] = $second;
        Rule::creating(function (Rule $rule): ?bool {
            return $rule->name === 'cancel' ? false : null;
        });
        try {
            Rulecraft::import(PolicyData::json($document));
            $this->fail('Expected canceled persistence to fail import.');
        } catch (InvalidPolicyException $error) {
            $this->assertStringContainsString('was canceled', $error->getMessage());
        }
        $this->assertSame(0, RuleGroup::count());
        $this->assertSame(0, Rule::count());
    }

    public function test_expression_functions_are_checked_but_never_executed(): void
    {
        $document = PolicyData::document();
        $document->group->rules[0]->expression = 'eligible(score)';
        config(['rulecraft.expression_providers' => [CustomerFunctions::class]]);
        Rulecraft::clearResolvedInstances();
        CustomerFunctions::$calls = 0;
        Rulecraft::import(PolicyData::json($document));
        Rulecraft::export('approval');
        $this->assertSame(0, CustomerFunctions::$calls);
    }

    public function test_unknown_expression_functions_are_rejected(): void
    {
        $document = PolicyData::document();
        $document->group->rules[0]->expression = 'unknown_function(amount)';
        $this->expectException(InvalidPolicyException::class);
        Rulecraft::import(PolicyData::json($document));
    }

    public function test_handlers_are_never_resolved_during_transfer(): void
    {
        Rulecraft::registerAction('unresolvable', InjectedAction::class);
        $this->app->bind(InjectedAction::class, function (): void {
            $this->fail('An action handler must not be resolved during policy transfer.');
        });
        $document = PolicyData::document();
        $document->group->rules[0]->action = 'unresolvable';
        Rulecraft::import(PolicyData::json($document));
        $exported = json_decode(Rulecraft::export('approval'));
        $this->assertSame('unresolvable', $exported->group->rules[0]->action);
    }

    public function test_import_preserves_parameter_objects_lists_and_float_values(): void
    {
        $document = PolicyData::document();
        $document->group->rules[0]->action_parameters = (object) [
            'rate' => 1.0, 'empty' => (object) [], 'list' => [],
        ];
        Rulecraft::import(PolicyData::json($document));
        $exported = json_decode(Rulecraft::export('approval'));
        $parameters = $exported->group->rules[0]->action_parameters;
        $this->assertSame(1.0, $parameters->rate);
        $this->assertInstanceOf(stdClass::class, $parameters->empty);
        $this->assertSame([], $parameters->list);
    }

    public function test_groups_with_only_children_and_empty_groups_survive_transfer(): void
    {
        $document = PolicyData::document();
        $document->group->rules = [];
        $document->group->groups = [PolicyData::document('child')->group];
        Rulecraft::import(PolicyData::json($document));
        $this->assertTrue(
            Rulecraft::group('approval')->evaluate(['amount' => 1, 'limit' => 2])->passed()
        );
        $exported = json_decode(Rulecraft::export('approval'));
        $this->assertSame([], $exported->group->rules);

        $empty = PolicyData::document('empty');
        $empty->group->rules = [];
        Rulecraft::import(PolicyData::json($empty));
        $this->assertTrue(Rulecraft::group('empty')->evaluate([])->failed());
        $this->assertSame([], json_decode(Rulecraft::export('empty'))->group->groups);
    }

    public function test_invalid_stored_expression_cannot_be_exported_even_if_disabled(): void
    {
        $group = $this->group('approval', ['amount >']);
        $group->update(['enabled' => false]);
        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage('group.rules.0.expression');
        Rulecraft::export('approval');
    }

    public function test_group_schemas_stay_application_managed(): void
    {
        config(['rulecraft.context_rules' => ['approval' => ['required_value' => 'required']]]);
        Rulecraft::import(PolicyData::json(PolicyData::document()));
        $result = Rulecraft::group('approval')->evaluate(['amount' => 1, 'limit' => 2]);
        $this->assertArrayHasKey('required_value', $result->validationErrors());
        $document = json_decode(Rulecraft::export('approval'));
        $this->assertSame(['version', 'group'], array_keys(get_object_vars($document)));
    }
}
