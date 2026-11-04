<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Support\Facades\Artisan;
use Thuraaung\Rulecraft\Builder\RuleGroupBuilder;
use Thuraaung\Rulecraft\Exceptions\StalePolicyException;
use Thuraaung\Rulecraft\Facades\Rulecraft;

class OutcomeMessageTest extends TestCase
{
    public function test_failed_message_joins_three_failures_including_nested_rules(): void
    {
        $group = $this->group('approval', []);
        $group->rules()->createMany([
            ['name' => 'branch', 'expression' => 'false',
                'failure_message' => 'The branches must match.'],
            ['name' => 'limit', 'expression' => 'false'],
            ['name' => 'pass', 'expression' => 'true', 'success_message' => 'Passed.'],
            ['name' => 'disabled', 'expression' => 'false', 'enabled' => false],
            ['name' => 'error', 'expression' => 'missing > 0'],
        ]);
        $child = $group->groups()->create(['name' => 'alternative', 'match' => 'all']);
        $child->rules()->create([
            'name' => 'special', 'expression' => 'false',
            'failure_message' => 'Special approval is required.',
        ]);
        $result = Rulecraft::group('approval')->evaluate([]);
        $this->assertSame('The branches must match. Rule "limit" did not pass. '
            .'Special approval is required.', $result->failedMessage());
        $this->assertSame('The branches must match.; Rule "limit" did not pass.; '
            .'Special approval is required.', $result->failedMessage('; '));
        $this->assertSame('Special approval is required.',
            $result->groups()->get('alternative')->failedMessage());
        $this->assertSame('', $result->passedMessage());
        $this->assertTrue($result->hasErrors());
    }

    public function test_success_messages_are_optional_and_snapshot_the_evaluated_text(): void
    {
        $group = $this->group('approval', []);
        $group->rules()->createMany([
            ['name' => 'first', 'expression' => 'true', 'success_message' => 'First passed.'],
            ['name' => 'second', 'expression' => 'true', 'success_message' => 'Second passed.'],
            ['name' => 'silent', 'expression' => 'true'],
        ]);
        $result = Rulecraft::group('approval')->evaluate([]);
        $this->assertSame('First passed. Second passed.', $result->passedMessage());
        $this->assertSame('', $result->failedMessage());
        $rules = $result->rules();
        $this->assertSame('First passed.', $rules[0]->message());
        $rules[0]->rule()->fill(['success_message' => 'Changed.', 'enabled' => false]);
        $this->assertSame('First passed.', $rules[0]->message());
        $this->assertSame('First passed. Second passed.', $result->passedMessage());
    }

    public function test_blank_success_messages_have_no_generated_default(): void
    {
        $group = $this->group('approval', []);
        foreach ([null, '', " \n\t"] as $index => $message) {
            $group->rules()->create(['name' => 'rule-'.$index, 'expression' => 'true',
                'success_message' => $message]);
        }
        $result = Rulecraft::group('approval')->evaluate([]);
        $this->assertTrue($result->passed());
        $this->assertSame('', $result->passedMessage());
        foreach ($result->rules() as $rule) {
            $this->assertNull($rule->message());
        }
    }

    public function test_success_aggregation_excludes_passing_rules_in_failed_branches(): void
    {
        $root = $this->group('approval', [], 'any');
        $root->rules()->create(['name' => 'direct', 'expression' => 'true',
            'success_message' => 'Direct passed.']);
        $failed = $root->groups()->create(['name' => 'failed', 'match' => 'all']);
        $failed->rules()->createMany([
            ['name' => 'hidden', 'expression' => 'true',
                'success_message' => 'Do not include this failed branch.'],
            ['name' => 'no', 'expression' => 'false', 'failure_message' => 'Denied.'],
        ]);
        $passed = $root->groups()->create(['name' => 'passed', 'match' => 'all']);
        $passed->rules()->create(['name' => 'yes', 'expression' => 'true',
            'success_message' => 'Child passed.']);
        $result = Rulecraft::group('approval')->evaluate([]);
        $this->assertTrue($result->passed());
        $this->assertCount(3, $result->matchedRules());
        $this->assertSame('Direct passed. Child passed.', $result->passedMessage());
        $this->assertSame('Denied.', $result->failedMessage());
        $this->assertSame('', $result->groups()->get('failed')->passedMessage());
    }

    public function test_disabled_and_error_rules_do_not_have_success_messages(): void
    {
        $group = $this->group('approval', []);
        $group->rules()->createMany([
            ['name' => 'disabled', 'expression' => 'true', 'enabled' => false,
                'success_message' => 'Not evaluated.'],
            ['name' => 'error', 'expression' => 'missing > 0',
                'success_message' => 'Must not conceal an error.'],
        ]);
        $result = Rulecraft::group('approval')->evaluate([]);
        $this->assertSame('', $result->passedMessage());
        $this->assertSame('', $result->failedMessage());
        $this->assertTrue($result->hasErrors());
        foreach ($result->rules() as $rule) {
            $this->assertNull($rule->message());
        }
    }

    public function test_separators_do_not_leak_and_zero_text_is_preserved(): void
    {
        $group = $this->group('approval', []);
        $group->rules()->createMany([
            ['name' => 'zero', 'expression' => 'true', 'success_message' => '0'],
            ['name' => 'second', 'expression' => 'true', 'success_message' => 'Second.'],
        ]);
        $result = Rulecraft::group('approval')->evaluate([]);
        $this->assertSame('0 • Second.', $result->passedMessage(' • '));
        $this->assertSame('0 Second.', $result->passedMessage());
        $this->assertSame('0Second.', $result->passedMessage(''));
        $group->rules()->update(['expression' => 'false', 'failure_message' => '0']);
        $result = Rulecraft::group('approval')->evaluate([]);
        $this->assertSame('0; 0', $result->failedMessage('; '));
        $this->assertSame('0 0', $result->failedMessage());
    }

    public function test_fail_fast_messages_include_only_visited_rules(): void
    {
        $group = $this->group('approval', []);
        $group->rules()->createMany([
            ['name' => 'first', 'expression' => 'false', 'failure_message' => 'First.'],
            ['name' => 'second', 'expression' => 'false', 'failure_message' => 'Second.'],
        ]);
        $this->assertSame('First.', Rulecraft::group('approval')->stopOnFailure()
            ->evaluate([])->failedMessage());
        $this->assertSame('First. Second.', Rulecraft::group('approval')
            ->evaluate([])->failedMessage());
    }

    public function test_empty_disabled_and_context_invalid_groups_have_empty_messages(): void
    {
        $empty = $this->group('empty', []);
        $disabled = $this->group('disabled', ['true']);
        $disabled->update(['enabled' => false]);
        $this->group('invalid', ['true']);
        config(['rulecraft.context_rules' => ['invalid' => ['amount' => 'required']]]);
        foreach ([$empty->name, $disabled->name, 'invalid'] as $name) {
            $result = Rulecraft::group($name)->evaluate([]);
            $this->assertTrue($result->failed());
            $this->assertSame('', $result->failedMessage());
            $this->assertSame('', $result->passedMessage());
        }
    }

    public function test_command_prints_configured_success_text(): void
    {
        $this->withoutMockingConsoleOutput();
        $group = $this->group('approval', []);
        $group->rules()->create(['name' => 'limit', 'expression' => 'true',
            'success_message' => '<info>Within limit.</info>']);
        $code = Artisan::call('rulecraft:test', ['group' => 'approval', '--data' => '{}']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString(
            'PASS  limit: <info>Within limit.</info>', Artisan::output()
        );
    }

    public function test_success_message_edits_invalidate_builder_revisions(): void
    {
        $group = $this->group();
        $builder = $this->app->make(RuleGroupBuilder::class);
        $loaded = $builder->load('approval');
        $group->rules()->first()->update(['success_message' => 'Changed elsewhere.']);
        $this->expectException(StalePolicyException::class);
        $builder->save($loaded['group'], $loaded['revision']);
    }
}
