<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Thuraaung\Rulecraft\Builder\DefinitionCompiler;
use Thuraaung\Rulecraft\Builder\RuleGroupBuilder;
use Thuraaung\Rulecraft\Events\GroupEvaluated;
use Thuraaung\Rulecraft\Exceptions\InvalidPolicyException;
use Thuraaung\Rulecraft\Exceptions\StalePolicyException;
use Thuraaung\Rulecraft\ExpressionEvaluator;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RolePermissionRule;
use Thuraaung\Rulecraft\Models\Rule;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Tests\Fixtures\BuilderData;
use Thuraaung\Rulecraft\Tests\Fixtures\CustomerFunctions;
use Thuraaung\Rulecraft\Tests\Fixtures\User;

class BuilderTest extends TestCase
{
    /** @var RuleGroupBuilder */
    private $builder;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rulecraft.builder.fields' => BuilderData::fields()]);
        $this->builder = $this->app->make(RuleGroupBuilder::class);
    }

    public function test_visual_values_are_escaped_without_changing_their_meaning(): void
    {
        $compiler = $this->app->make(DefinitionCompiler::class);
        foreach (['Green', 'quote" and true', '\\path\\file', 'မြန်မာ', "\0\n\t\r\x01"] as $value) {
            $definitionJson = json_encode(BuilderData::definition('zone', '===', $value));
            $definition = json_decode($definitionJson, true);
            $expression = $compiler->compile($definition);
            $this->assertTrue($this->app->make(ExpressionEvaluator::class)
                ->evaluate($expression, ['zone' => $value]));
            $this->assertFalse($this->app->make(ExpressionEvaluator::class)
                ->evaluate($expression, ['zone' => 'different']));
        }
    }

    public function test_field_references_nested_paths_and_typed_lists_compile(): void
    {
        $draft = BuilderData::group();
        $draft->rules[0]->builder_definition = BuilderData::definition('amount', '<=', 200000);
        $draft->rules[0]->builder_definition->operand = (object) [
            'type' => 'field', 'field' => 'limit',
        ];
        $normalized = $this->builder->validate($draft);
        $this->assertSame('amount <= limit', $normalized->rules[0]->expression);
        $this->assertTrue($this->builder->preview($draft, ['amount' => 1, 'limit' => 2])->passed());
        $draft->rules[0]->builder_definition = BuilderData::definition(
            'customer.country', 'in', ['MM', 'TH']
        );
        $result = $this->builder->preview($draft, ['customer' => ['country' => 'MM']]);
        $this->assertTrue($result->passed());
        $result = $this->builder->preview($draft, ['customer' => ['country' => 'US']]);
        $this->assertFalse($result->passed());
    }

    public function test_visual_input_cannot_inject_fields_operators_or_wrong_types(): void
    {
        $invalid = [
            BuilderData::definition('zone) or true', '==', 'Green'),
            BuilderData::definition('zone', 'or true', 'Green'),
            BuilderData::definition('special', '<=', true),
            BuilderData::definition('product_id', 'in', ['2', '5']),
            BuilderData::definition('customer.country', '==', 'US'),
            BuilderData::definition('amount', '==', (object) ['bad' => true]),
        ];
        foreach ($invalid as $definition) {
            $draft = BuilderData::group();
            $draft->rules[0]->builder_definition = $definition;
            try {
                $this->builder->save($draft);
                $this->fail('Invalid visual rule was accepted.');
            } catch (InvalidPolicyException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
        $this->assertSame(0, RuleGroup::count());
    }

    public function test_preview_uses_nested_groups_without_writes_or_completion_events(): void
    {
        $draft = BuilderData::group();
        $draft->rules[0]->expression = 'branch == user_branch';
        $child = BuilderData::group('limit-or-special');
        $child->match = 'any';
        $extra = clone $child->rules[0];
        $extra->name = 'special';
        $extra->expression = 'special === true';
        $extra->action = 'unregistered';
        $child->rules[] = $extra;
        $draft->groups[] = $child;
        $events = 0;
        Event::listen(GroupEvaluated::class, function () use (&$events): void {
            $events++;
        });
        DB::enableQueryLog();
        $result = $this->builder->preview($draft, ['branch' => 1, 'user_branch' => 1,
            'amount' => 300000, 'limit' => 200000, 'special' => true]);
        $this->assertTrue($result->passed());
        $this->assertCount(3, $result->allRules());
        $this->assertTrue($result->groups()->get('limit-or-special')->passed());
        $this->assertSame([], DB::getQueryLog());
        $this->assertSame(0, $events);
        $this->assertSame(0, RuleGroup::count());
    }

    public function test_preview_honors_validation_disabled_empty_and_priority_semantics(): void
    {
        $draft = BuilderData::group();
        config(['rulecraft.context_rules' => ['approval' => ['amount' => 'required|numeric']]]);
        $result = $this->builder->preview($draft, ['amount' => 'invalid']);
        $this->assertArrayHasKey('amount', $result->validationErrors());
        $draft->enabled = false;
        $this->assertTrue($this->builder->preview($draft, [])->disabled());
        $draft->enabled = true;
        $draft->rules = [];
        $this->assertTrue($this->builder->preview($draft, ['amount' => 1])->failed());
        $draft->rules = BuilderData::group()->rules;
        $extra = clone $draft->rules[0];
        $extra->name = 'first';
        $extra->priority = 20;
        $extra->expression = 'false';
        $draft->rules[] = $extra;
        $result = $this->builder->preview($draft, ['amount' => 1, 'limit' => 2]);
        $this->assertSame('first', $result->rules()->first()->rule()->name);
    }

    public function test_validation_does_not_execute_custom_functions_but_preview_does(): void
    {
        config(['rulecraft.expression_providers' => [CustomerFunctions::class]]);
        $this->builder = $this->app->make(RuleGroupBuilder::class);
        CustomerFunctions::$calls = 0;
        $draft = BuilderData::group();
        $draft->rules[0]->expression = 'eligible(score)';
        $this->builder->validate($draft);
        $this->assertSame(0, CustomerFunctions::$calls);
        $this->assertTrue($this->builder->preview($draft, ['score' => 700])->passed());
        $this->assertSame(1, CustomerFunctions::$calls);
    }

    public function test_save_retains_ids_and_permission_mappings(): void
    {
        $root = $this->builder->save(BuilderData::group());
        $ruleId = $root->rules()->first()->getKey();
        $this->installPermissions();
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'approve', 'guard_name' => 'web']);
        $mapping = RolePermissionRule::assign($role, $permission, $root);
        $loaded = $this->builder->load('approval');
        $loaded['group']->name = 'updated';
        $loaded['group']->rules[0]->expression = 'amount < limit';
        $saved = $this->builder->save($loaded['group'], $loaded['revision']);
        $this->assertSame($root->getKey(), $saved->getKey());
        $this->assertSame($ruleId, $saved->rules()->first()->getKey());
        $this->assertSame((string) $root->getKey(), (string) $mapping->fresh()->rule_group_id);
        $this->assertSame('updated', $mapping->fresh()->group->name);
    }

    public function test_foreign_duplicate_and_moved_ids_are_rejected(): void
    {
        $root = $this->builder->save(BuilderData::group());
        $other = $this->group('other', ['true']);
        $loaded = $this->builder->load('approval');
        $loaded['group']->rules[0]->id = (string) $other->rules()->first()->getKey();
        try {
            $this->builder->save($loaded['group'], $loaded['revision']);
            $this->fail('Expected a foreign ID to be rejected.');
        } catch (InvalidPolicyException $error) {
            $this->assertStringContainsString('IDs', $error->getMessage());
        }
        $loaded = $this->builder->load('approval');
        $child = BuilderData::group('child');
        $child->rules = $loaded['group']->rules;
        $loaded['group']->rules = [];
        $loaded['group']->groups[] = $child;
        $this->expectException(InvalidPolicyException::class);
        $this->builder->save($loaded['group'], $loaded['revision']);
    }

    public function test_removed_children_are_deleted_without_replacing_remaining_rows(): void
    {
        $draft = BuilderData::group();
        $draft->groups[] = BuilderData::group('child');
        $root = $this->builder->save($draft);
        $ruleId = $root->rules()->first()->getKey();
        $loaded = $this->builder->load('approval');
        $loaded['group']->groups = [];
        $this->builder->save($loaded['group'], $loaded['revision']);
        $this->assertSame(1, RuleGroup::count());
        $this->assertSame(1, Rule::count());
        $this->assertSame($ruleId, $root->rules()->first()->getKey());
    }

    public function test_canceled_saves_roll_back_deletions_and_prior_updates(): void
    {
        $draft = BuilderData::group();
        $draft->groups[] = BuilderData::group('child');
        $this->builder->save($draft);
        $loaded = $this->builder->load('approval');
        $loaded['group']->name = 'changed';
        $loaded['group']->groups = [];
        $loaded['group']->rules[0]->name = 'cancel';
        Rule::saving(function (Rule $rule): ?bool {
            return $rule->name === 'cancel' ? false : null;
        });
        try {
            $this->builder->save($loaded['group'], $loaded['revision']);
            $this->fail('Expected a canceled save.');
        } catch (InvalidPolicyException $error) {
            $this->assertStringContainsString('canceled', $error->getMessage());
        }
        $this->assertSame(2, RuleGroup::count());
        $this->assertSame(2, Rule::count());
        $this->assertNotNull(RuleGroup::where('name', 'approval')->first());
        $this->assertNull(RuleGroup::where('name', 'changed')->first());
    }

    public function test_stale_revisions_detect_changes_inside_the_tree(): void
    {
        $draft = BuilderData::group();
        $draft->groups[] = BuilderData::group('child');
        $root = $this->builder->save($draft);
        $loaded = $this->builder->load('approval');
        $root->groups()->first()->rules()->first()->update(['expression' => 'false']);
        $this->expectException(StalePolicyException::class);
        $this->builder->save($loaded['group'], $loaded['revision']);
    }

    public function test_visual_metadata_and_json_parameter_types_survive_editing(): void
    {
        $draft = BuilderData::group();
        $draft->rules[0]->builder_definition = BuilderData::definition('amount', '===', 1.0);
        $draft->rules[0]->action_parameters_json = '{"rate":1.0,"object":{},"list":[]}';
        $root = $this->builder->save($draft);
        $this->assertSame('amount === 1.0', $root->rules()->first()->expression);
        $loaded = $this->builder->load('approval');
        $wire = json_decode(json_encode($loaded['group'])); // Simulate a JS JSON round-trip.
        $this->builder->save($wire, $loaded['revision']);
        $rule = $root->rules()->first();
        $this->assertSame('amount === 1.0', $rule->expression);
        $this->assertNotNull($rule->builder_definition);
        $parameters = json_decode($rule->getRawOriginal('action_parameters'));
        $this->assertSame(1.0, $parameters->rate);
        $this->assertInstanceOf(\stdClass::class, $parameters->object);
        $this->assertSame([], $parameters->list);
    }

    public function test_raw_expressions_and_visual_definitions_survive_catalog_removal(): void
    {
        $root = $this->group('approval', [' (amount <= limit)  ']);
        $loaded = $this->builder->load('approval');
        $this->assertNull($loaded['group']->rules[0]->builder_definition);
        $this->builder->save($loaded['group'], $loaded['revision']);
        $this->assertSame(' (amount <= limit)  ', $root->rules()->first()->expression);
        $draft = BuilderData::group('visual');
        $draft->rules[0]->builder_definition = BuilderData::definition('amount', '<=', 20);
        $visual = $this->builder->save($draft);
        config(['rulecraft.builder.fields' => []]);
        $loaded = $this->builder->load('visual');
        $this->assertNotNull($loaded['group']->rules[0]->builder_definition);
        $this->assertTrue($this->builder->preview($loaded['group'], ['amount' => 10])->passed());
        $this->builder->save($loaded['group'], $loaded['revision']);
        $this->assertSame('amount <= 20', $visual->rules()->first()->expression);
        $this->assertNotNull($visual->rules()->first()->builder_definition);
    }

    public function test_visual_definitions_reject_development_version_flags(): void
    {
        foreach ([1, 2] as $version) {
            $draft = BuilderData::group();
            $definition = BuilderData::definition('amount', '<=', 20);
            $definition->version = $version;
            $draft->rules[0]->builder_definition = $definition;
            try {
                $this->builder->save($draft);
                $this->fail('Obsolete metadata was accepted.');
            } catch (InvalidPolicyException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
        $this->assertSame(0, RuleGroup::count());
    }

    public function test_normal_model_edits_clear_stale_visual_metadata(): void
    {
        $draft = BuilderData::group();
        $draft->rules[0]->builder_definition = BuilderData::definition('amount', '<=', 20);
        $group = $this->builder->save($draft);
        $rule = $group->rules()->first();
        $rule->update(['expression' => 'false']);
        $this->assertNull($rule->fresh()->builder_definition);
        $this->assertSame('false', $rule->fresh()->expression);
    }

    public function test_version_two_transfer_preserves_definitions_without_a_field_catalog(): void
    {
        $draft = BuilderData::group();
        $draft->rules[0]->builder_definition = BuilderData::definition('amount', '<=', 20);
        $group = $this->builder->save($draft);
        $json = Rulecraft::export('approval');
        $this->assertSame(2, json_decode($json)->version);
        $group->delete();
        config(['rulecraft.builder.fields' => []]);
        $imported = Rulecraft::import($json);
        $this->assertNotNull($imported->rules()->first()->builder_definition);
        $this->assertSame($json, Rulecraft::export('approval'));
    }

    public function test_version_two_cannot_import_a_definition_expression_mismatch(): void
    {
        $draft = BuilderData::group();
        $draft->rules[0]->builder_definition = BuilderData::definition('amount', '<=', 20);
        $group = $this->builder->save($draft);
        $json = json_decode(Rulecraft::export('approval'));
        $group->delete();
        $json->group->rules[0]->expression = 'true';
        $this->expectException(InvalidPolicyException::class);
        Rulecraft::import(json_encode($json));
    }

    public function test_fail_fast_remains_enabled_for_all_groups_nested_inside_any_groups(): void
    {
        $root = $this->group('approval', ['true']);
        $any = $root->groups()->create(['name' => 'alternatives', 'match' => 'any']);
        $all = $any->groups()->create(['name' => 'bad', 'match' => 'all']);
        $all->rules()->createMany([
            ['name' => 'false', 'expression' => 'false'],
            ['name' => 'missing', 'expression' => 'missing > 0'],
        ]);
        $any->rules()->create(['name' => 'true', 'expression' => 'true']);
        $result = Rulecraft::group('approval')->stopOnFailure()->evaluate([]);
        $this->assertTrue($result->passed());
        $stopped = $result->groups()->get('alternatives')->groups()->get('bad');
        $this->assertCount(1, $stopped->rules());
        $this->assertFalse($result->hasErrors());
        if (method_exists(Model::class, 'preventLazyLoading')) {
            Model::preventLazyLoading();
            try {
                $result = Rulecraft::group('approval')->stopOnFailure()->evaluate([]);
                $this->assertTrue($result->passed());
            } finally {
                Model::preventLazyLoading(false);
            }
        }
    }

    public function test_backend_requires_opt_in_authentication_and_ability(): void
    {
        $this->installPermissions();
        $this->getJson(route('rulecraft.builder.load', ['group' => 'approval']))->assertStatus(401);
        $this->actingAs(new User(['id' => 1, 'name' => 'Admin']));
        $this->getJson(route('rulecraft.builder.load', ['group' => 'approval']))->assertStatus(404);
        config(['rulecraft.builder.enabled' => true]);
        $this->getJson(route('rulecraft.builder.load', ['group' => 'approval']))->assertStatus(403);
    }

    public function test_http_create_validate_preview_and_save_round_trip(): void
    {
        $this->authorize();
        $draft = BuilderData::group();
        $draft->rules[0]->builder_definition = BuilderData::definition('amount', '<=', 20);
        $this->postJson(route('rulecraft.builder.validate'), ['group' => $draft])
            ->assertOk()->assertJsonPath('group.rules.0.expression', 'amount <= 20');
        $this->assertSame(0, RuleGroup::count());
        $this->postJson(route('rulecraft.builder.preview'), ['group' => $draft,
            'context' => ['amount' => 10]])->assertOk()->assertJsonPath('result.passed', true);
        $saved = $this->postJson(route('rulecraft.builder.save'), ['group' => $draft])->assertOk();
        $this->assertSame(1, RuleGroup::count());
        $body = json_decode($saved->getContent(), true);
        $body['group']['rules'][0]['enabled'] = false;
        $this->postJson(route('rulecraft.builder.save'), $body)->assertOk();
        $this->postJson(route('rulecraft.builder.save'), $body)->assertStatus(409);
        $this->getJson(route('rulecraft.builder.load', ['group' => 'approval']))
            ->assertOk()->assertJsonPath('group.rules.0.enabled', false);
    }

    public function test_http_preview_exposes_failure_messages_separately_from_errors(): void
    {
        $this->authorize();
        $draft = BuilderData::group();
        $draft->rules[0]->failure_message = '<strong>Over limit.</strong>';
        $draft->rules[0]->success_message = '<em>Within limit.</em>';
        $this->postJson(route('rulecraft.builder.preview'), ['group' => $draft,
            'context' => ['amount' => 300, 'limit' => 200]])->assertOk()
            ->assertJsonPath('result.rules.0.message', '<strong>Over limit.</strong>')
            ->assertJsonPath('result.rules.0.error', null)
            ->assertJsonPath('result.failed_message', '<strong>Over limit.</strong>')
            ->assertJsonPath('result.passed_message', '');
        $this->postJson(route('rulecraft.builder.preview'), ['group' => $draft,
            'context' => ['amount' => 100, 'limit' => 200]])->assertOk()
            ->assertJsonPath('result.rules.0.message', '<em>Within limit.</em>')
            ->assertJsonPath('result.passed_message', '<em>Within limit.</em>')
            ->assertJsonPath('result.failed_message', '');
        $this->postJson(route('rulecraft.builder.preview'), ['group' => $draft,
            'context' => (object) []])->assertOk()
            ->assertJsonPath('result.rules.0.message', null)
            ->assertJsonPath('result.has_errors', true);
        $draft->rules[0]->enabled = false;
        $this->postJson(route('rulecraft.builder.preview'), ['group' => $draft,
            'context' => (object) []])->assertOk()
            ->assertJsonPath('result.rules.0.message', null)
            ->assertJsonPath('result.rules.0.disabled', true);
        $this->postJson(route('rulecraft.builder.save'), ['group' => $draft])->assertOk()
            ->assertJsonPath('group.rules.0.failure_message', '<strong>Over limit.</strong>')
            ->assertJsonPath('group.rules.0.success_message', '<em>Within limit.</em>');
        $draft->rules[0]->failure_message = false;
        $this->postJson(route('rulecraft.builder.validate'), ['group' => $draft])
            ->assertStatus(422);
    }

    public function test_http_rejects_invalid_context_and_preserves_float_preview_input(): void
    {
        $this->authorize();
        $draft = BuilderData::group();
        $draft->rules[0]->expression = 'amount === 1.0';
        $this->postJson(route('rulecraft.builder.preview'), ['group' => $draft,
            'context_json' => '{"amount":1.0}'])->assertOk()->assertJsonPath('result.passed', true);
        $this->postJson(route('rulecraft.builder.preview'), ['group' => $draft,
            'context_json' => '{"amount":1}'])->assertOk()->assertJsonPath('result.passed', false);
        foreach (['[]', 'null', '{invalid', '{"amount":1e309}'] as $json) {
            $this->postJson(route('rulecraft.builder.preview'), ['group' => $draft,
                'context_json' => $json])->assertStatus(422);
        }
    }

    public function test_blade_component_uses_custom_styles_and_loads_shared_assets_once(): void
    {
        $this->authorize();
        config(['rulecraft.builder.stylesheet' => 'css/rulecraft-overrides.css']);
        $html = $this->render('<x-rulecraft::builder group="approval" />'
            .'<x-rulecraft::builder />');
        $this->assertSame(2, substr_count($html, 'data-rulecraft-builder'));
        $this->assertSame(1, substr_count($html, 'defer></script>'));
        $this->assertSame(1, substr_count($html, 'rulecraft-overrides.css'));
        $this->assertStringContainsString('rc-theme-custom', $html);
        $this->assertStringNotContainsString('bootstrap.css', $html);
    }

    public function test_duplicate_ids_depth_and_group_names_are_rejected_before_writes(): void
    {
        $root = $this->builder->save(BuilderData::group());
        $loaded = $this->builder->load('approval');
        $loaded['group']->rules[] = clone $loaded['group']->rules[0];
        try {
            $this->builder->save($loaded['group'], $loaded['revision']);
            $this->fail('Expected duplicate rule IDs to fail.');
        } catch (InvalidPolicyException $error) {
            $this->assertSame(1, Rule::count());
        }
        $draft = BuilderData::group('new');
        $draft->groups[] = BuilderData::group('new');
        try {
            $this->builder->save($draft);
            $this->fail('Expected duplicate group names to fail.');
        } catch (InvalidPolicyException $error) {
            $this->assertSame(1, RuleGroup::count());
        }
        config(['rulecraft.max_depth' => 1]);
        $draft->groups[0]->name = 'child';
        $this->expectException(InvalidPolicyException::class);
        $this->app->make(RuleGroupBuilder::class)->save($draft);
    }

    public function test_bulk_expression_edits_export_as_code_without_stale_metadata(): void
    {
        $draft = BuilderData::group();
        $draft->rules[0]->builder_definition = BuilderData::definition('amount', '<=', 20);
        $root = $this->builder->save($draft);
        Rule::query()->where('rule_group_id', $root->getKey())->update(['expression' => 'false']);
        $exported = json_decode(Rulecraft::export('approval'));
        $this->assertSame(1, $exported->version);
        $this->assertSame('false', $exported->group->rules[0]->expression);
        $this->assertNull($this->builder->load('approval')['group']->rules[0]->builder_definition);
    }

    public function test_precise_float_and_large_integer_literals_survive_browser_transport(): void
    {
        $draft = BuilderData::group();
        $draft->rules[0]->builder_definition = BuilderData::definition(
            'amount', '===', 1.2345678901234567
        );
        $root = $this->builder->save($draft);
        $original = $root->rules()->first()->expression;
        $loaded = $this->builder->load('approval');
        $wire = json_decode(json_encode($loaded['group']));
        $this->builder->save($wire, $loaded['revision']);
        $this->assertSame($original, $root->rules()->first()->expression);
        $draft = BuilderData::group('integer');
        $definition = BuilderData::definition('product_id', '===', '18014398509481985');
        $definition->operand->value_type = 'integer';
        $draft->rules[0]->builder_definition = $definition;
        $this->assertTrue($this->builder->preview($draft, [
            'product_id' => 18014398509481985,
        ])->passed());
        $this->assertSame('product_id === 18014398509481985',
            $this->builder->validate($draft)->rules[0]->expression);
        $fields = BuilderData::fields();
        $fields['amount']['options'] = [1, 2];
        config(['rulecraft.builder.fields' => $fields]);
        $numeric = BuilderData::group('numeric');
        $numeric->rules[0]->builder_definition = BuilderData::definition('amount', '===', 1.0);
        $this->assertTrue($this->builder->preview($numeric, ['amount' => 1.0])->passed());
    }

    public function test_every_backend_operation_requires_the_management_ability(): void
    {
        $this->installPermissions();
        config(['rulecraft.builder.enabled' => true]);
        $this->actingAs(new User(['id' => 1, 'name' => 'Denied']));
        Gate::define('manage-rulecraft', function (): bool {
            return false;
        });
        foreach (['validate', 'preview', 'save'] as $operation) {
            $this->postJson(route('rulecraft.builder.'.$operation), [
                'group' => BuilderData::group(), 'context' => (object) [],
            ])->assertStatus(403);
        }
        $this->assertSame(0, RuleGroup::count());
        Gate::define('manage-rulecraft', function (): bool {
            return true;
        });
        $this->getJson(route('rulecraft.builder.asset', ['file' => 'composer.json']))
            ->assertStatus(404);
    }

    private function authorize(): void
    {
        $this->installPermissions();
        config(['rulecraft.builder.enabled' => true]);
        $this->actingAs(new User(['id' => 1, 'name' => 'Admin']));
        Gate::define('manage-rulecraft', function (User $user): bool {
            return $user->name === 'Admin';
        });
    }

    private function render(string $template): string
    {
        $__env = $this->app->make('view');
        $compiled = $this->app->make('blade.compiler')->compileString($template);
        ob_start();
        try {
            eval('?>'.$compiled);

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
