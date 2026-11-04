<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Support\Facades\Artisan;
use stdClass;
use Thuraaung\Rulecraft\Builder\RuleGroupBuilder;
use Thuraaung\Rulecraft\Exceptions\InvalidPolicyException;
use Thuraaung\Rulecraft\Exceptions\StalePolicyException;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Tests\Fixtures\BuilderData;
use Thuraaung\Rulecraft\Tests\Fixtures\PolicyData;

class FailureMessageTest extends TestCase
{
    public function test_custom_messages_describe_false_conditions_without_errors(): void
    {
        $group = $this->group('approval', []);
        $rule = $group->rules()->create([
            'name' => 'limit', 'expression' => 'amount <= limit',
            'failure_message' => 'The loan amount exceeds your approval limit.',
        ]);
        $result = Rulecraft::group('approval')->evaluate(['amount' => 300, 'limit' => 200]);
        $failure = $result->failedRules()->first();

        $this->assertFalse($result->passed());
        $this->assertFalse($result->hasErrors());
        $this->assertNull($failure->error());
        $this->assertSame($rule->fresh()->failure_message, $failure->message());
    }

    public function test_missing_and_blank_messages_use_the_rule_name(): void
    {
        $group = $this->group('approval', []);
        $rule = $group->rules()->create(['name' => 'limit', 'expression' => 'false']);
        foreach ([null, '', " \n\t"] as $message) {
            $rule->update(['failure_message' => $message]);
            $result = Rulecraft::group('approval')->evaluate([]);
            $this->assertSame('Rule "limit" did not pass.',
                $result->failedRules()->first()->message());
        }
    }

    public function test_passing_disabled_and_error_results_have_no_failure_message(): void
    {
        $group = $this->group('approval', []);
        $group->rules()->createMany([
            ['name' => 'pass', 'expression' => 'true', 'failure_message' => 'Not used.'],
            ['name' => 'disabled', 'expression' => 'false', 'enabled' => false,
                'failure_message' => 'Not evaluated.'],
            ['name' => 'error', 'expression' => 'missing_value > 0',
                'failure_message' => 'Must not conceal an expression error.'],
        ]);
        $rules = Rulecraft::group('approval')->evaluate([])->rules()->values();

        $this->assertTrue($rules[0]->passed());
        $this->assertTrue($rules[1]->disabled());
        $this->assertNotNull($rules[2]->error());
        foreach ($rules as $rule) {
            $this->assertNull($rule->message());
        }
    }

    public function test_messages_are_snapshots_and_are_not_interpolated(): void
    {
        $group = $this->group('approval', []);
        $group->rules()->createMany([
            ['name' => 'custom', 'expression' => 'false',
                'failure_message' => 'Amount :amount must match {limit}.'],
            ['name' => 'default', 'expression' => 'false'],
        ]);
        $rules = Rulecraft::group('approval')->evaluate(['amount' => 300, 'limit' => 200])
            ->rules()->values();
        foreach ($rules as $rule) {
            $rule->rule()->fill(['name' => 'changed', 'failure_message' => 'Changed.']);
        }

        $this->assertSame('Amount :amount must match {limit}.', $rules[0]->message());
        $this->assertSame('Rule "default" did not pass.', $rules[1]->message());
    }

    public function test_failed_alternatives_keep_messages_when_an_any_group_passes(): void
    {
        $group = $this->group('approval', ['true'], 'any');
        $child = $group->groups()->create(['name' => 'alternative', 'match' => 'all']);
        $child->rules()->create([
            'name' => 'limit', 'expression' => 'false', 'failure_message' => 'Over limit.',
        ]);
        $result = Rulecraft::group('approval')->evaluate([]);

        $this->assertTrue($result->passed());
        $this->assertSame('Over limit.', $result->failedRules()->first()->message());
        $this->assertSame('Over limit.',
            $result->groups()->get('alternative')->failedRules()->first()->message());
    }

    public function test_messages_round_trip_in_both_policy_formats(): void
    {
        foreach ([1, 2] as $version) {
            $document = PolicyData::document('approval-'.$version);
            $document->version = $version;
            $message = "မြန်မာ <strong>Approval denied</strong>\nContact your manager.";
            $document->group->rules[0]->failure_message = $message;
            $document->group->rules[0]->success_message = 'Within limit.';
            if ($version === 2) {
                $definition = BuilderData::definition('amount', '<=', 200);
                $definition->operand = (object) ['type' => 'field', 'field' => 'limit'];
                $document->group->rules[0]->builder_definition = $definition;
            }
            $group = Rulecraft::import(PolicyData::json($document));
            $json = Rulecraft::export($group->name);
            $exported = json_decode($json);
            $this->assertSame($version, $exported->version);
            $this->assertSame($message, $exported->group->rules[0]->failure_message);
            $this->assertSame('Within limit.', $exported->group->rules[0]->success_message);
            $name = $group->name;
            $group->delete();
            Rulecraft::import($json);
            $this->assertSame($message, Rulecraft::group($name)
                ->evaluate(['amount' => 300, 'limit' => 200])->failedRules()->first()->message());
            $this->assertSame('Within limit.', Rulecraft::group($name)
                ->evaluate(['amount' => 100, 'limit' => 200])->passedMessage());
        }
    }

    public function test_omitted_optional_messages_default_to_null(): void
    {
        $document = PolicyData::document();
        unset($document->group->rules[0]->failure_message,
            $document->group->rules[0]->success_message);
        $group = Rulecraft::import(PolicyData::json($document));
        $this->assertNull($group->rules()->first()->failure_message);
        $this->assertNull($group->rules()->first()->success_message);
        $draft = BuilderData::group('draft');
        unset($draft->rules[0]->failure_message, $draft->rules[0]->success_message);
        $normalized = $this->app->make(RuleGroupBuilder::class)->validate($draft);
        $this->assertNull($normalized->rules[0]->failure_message);
        $this->assertNull($normalized->rules[0]->success_message);
    }

    public function test_invalid_message_types_are_rejected_before_writes(): void
    {
        foreach ([false, 42, [], new stdClass()] as $message) {
            foreach (['failure_message', 'success_message'] as $field) {
                $document = PolicyData::document();
                $document->group->rules[0]->$field = $message;
                try {
                    Rulecraft::import(PolicyData::json($document));
                    $this->fail('Invalid message was accepted.');
                } catch (InvalidPolicyException $error) {
                    $this->assertStringContainsString($field, $error->getMessage());
                }
                $draft = BuilderData::group();
                $draft->rules[0]->$field = $message;
                try {
                    $this->app->make(RuleGroupBuilder::class)->save($draft);
                    $this->fail('Invalid builder message was accepted.');
                } catch (InvalidPolicyException $error) {
                    $this->assertStringContainsString($field, $error->getMessage());
                }
            }
        }
        $this->assertSame(0, RuleGroup::count());
    }

    public function test_builder_preview_save_reload_update_and_clear_messages(): void
    {
        $builder = $this->app->make(RuleGroupBuilder::class);
        $draft = BuilderData::group();
        $draft->rules[0]->failure_message = 'Over limit.';
        $draft->rules[0]->success_message = 'Within limit.';
        $draft->rules[0]->builder_definition = BuilderData::definition('amount', '<=', 200);
        $preview = $builder->preview($draft, ['amount' => 300]);
        $this->assertSame('Over limit.', $preview->failedRules()->first()->message());
        $this->assertSame('Within limit.',
            $builder->preview($draft, ['amount' => 100])->passedMessage());
        $this->assertSame(0, RuleGroup::count());
        $builder->save($draft);
        $loaded = $builder->load('approval');
        $this->assertSame('Over limit.', $loaded['group']->rules[0]->failure_message);
        $this->assertSame('Within limit.', $loaded['group']->rules[0]->success_message);
        $loaded['group']->rules[0]->failure_message = 'Ask your manager.';
        $loaded['group']->rules[0]->success_message = 'Approved.';
        $builder->save($loaded['group'], $loaded['revision']);
        $loaded = $builder->load('approval');
        $this->assertSame('Ask your manager.', $loaded['group']->rules[0]->failure_message);
        $this->assertSame('Approved.', $loaded['group']->rules[0]->success_message);
        $loaded['group']->rules[0]->failure_message = null;
        $loaded['group']->rules[0]->success_message = null;
        $builder->save($loaded['group'], $loaded['revision']);
        $this->assertNull($builder->load('approval')['group']->rules[0]->failure_message);
        $this->assertNull($builder->load('approval')['group']->rules[0]->success_message);
    }

    public function test_message_only_changes_invalidate_builder_revisions(): void
    {
        $group = $this->group();
        $builder = $this->app->make(RuleGroupBuilder::class);
        $loaded = $builder->load('approval');
        $group->rules()->first()->update(['failure_message' => 'Changed elsewhere.']);

        $this->expectException(StalePolicyException::class);
        $builder->save($loaded['group'], $loaded['revision']);
    }

    public function test_command_prints_messages_as_text(): void
    {
        $this->withoutMockingConsoleOutput();
        $group = $this->group('approval', []);
        $message = '<error>Over limit</error>';
        $group->rules()->create([
            'name' => 'limit', 'expression' => 'false', 'failure_message' => $message,
        ]);
        $code = Artisan::call('rulecraft:test', ['group' => 'approval', '--data' => '{}']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  limit: '.$message, Artisan::output());
    }
}
