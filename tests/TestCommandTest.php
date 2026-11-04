<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Support\Facades\Artisan;

class TestCommandTest extends TestCase
{
    /** @var string */
    private $context = '{"amount": 100, "limit": 200}';

    public function test_passing_policy_is_reported_without_resolving_or_executing_actions(): void
    {
        $group = $this->group();
        $group->rules()->first()->update(['action' => 'unregistered']);

        $this->artisan('rulecraft:test', ['group' => 'approval', '--data' => $this->context])
            ->expectsOutput('PASS  approval [ALL]')
            ->expectsOutput('  PASS  condition-0')
            ->assertExitCode(0);
    }

    public function test_nested_results_show_all_branches_including_failures(): void
    {
        $root = $this->group('approval', ['true']);
        $child = $root->groups()->create(['name' => 'alternatives', 'match' => 'any']);
        $child->rules()->createMany([
            ['name' => 'over-limit', 'expression' => 'amount > limit'],
            ['name' => 'within-limit', 'expression' => 'amount <= limit'],
        ]);

        $this->artisan('rulecraft:test', ['group' => 'approval', '--data' => $this->context])
            ->expectsOutput('PASS  approval [ALL]')
            ->expectsOutput('  PASS  condition-0')
            ->expectsOutput('  PASS  alternatives [ANY]')
            ->expectsOutput('    FAIL  over-limit: Rule "over-limit" did not pass.')
            ->expectsOutput('    PASS  within-limit')
            ->assertExitCode(0);
    }

    public function test_a_valid_denial_returns_one(): void
    {
        $this->group('approval', ['false']);
        $this->artisan('rulecraft:test', ['group' => 'approval', '--data' => $this->context])
            ->expectsOutput('FAIL  approval [ALL]')
            ->expectsOutput('  FAIL  condition-0: Rule "condition-0" did not pass.')
            ->assertExitCode(1);
    }

    public function test_expression_errors_return_two_even_when_an_any_branch_matches(): void
    {
        $this->group('approval', ['true', 'unknown > 0'], 'any');
        $this->withoutMockingConsoleOutput();
        $this->assertSame(
            2,
            Artisan::call('rulecraft:test', ['group' => 'approval', '--data' => $this->context])
        );
        $output = Artisan::output();
        $this->assertStringContainsString('ERROR  approval [ANY]', $output);
        $this->assertStringContainsString('  PASS  condition-0', $output);
        $this->assertStringContainsString('ERROR  condition-1:', $output);
        $this->assertStringContainsString('unknown', $output);
    }

    public function test_a_missing_group_returns_two(): void
    {
        $this->artisan('rulecraft:test', ['group' => 'missing', '--data' => $this->context])
            ->expectsOutput('Unable to test group: Rule group [missing] does not exist.')
            ->assertExitCode(2);
    }

    public function test_an_empty_group_is_a_valid_denial_and_an_empty_object_is_valid_input(): void
    {
        $this->group('empty', []);
        $this->context = '{}';
        $this->artisan('rulecraft:test', ['group' => 'empty', '--data' => $this->context])
            ->expectsOutput('FAIL  empty [ALL]')
            ->assertExitCode(1);
    }

    public function test_invalid_json_and_non_object_roots_are_rejected(): void
    {
        $this->group('approval', ['true']);
        foreach ([
            '{invalid',
            '[]',
            '[{"amount": 100}]',
            'null',
            'true',
            '42',
            '"value"',
            'loan-context.json',
        ] as $json) {
            $this->context = $json;
            $this->withoutMockingConsoleOutput();
            $this->assertSame(
                2,
                Artisan::call('rulecraft:test', ['group' => 'approval', '--data' => $this->context])
            );
            $this->assertStringContainsString('Unable to test group:', Artisan::output());
        }
    }

    public function test_a_nonempty_json_string_is_required(): void
    {
        $this->artisan('rulecraft:test', ['group' => 'approval'])
            ->expectsOutput('Unable to test group: Provide a JSON context string with --data.')
            ->assertExitCode(2);

        $this->withoutMockingConsoleOutput();
        foreach (['', '   '] as $json) {
            $this->assertSame(
                2,
                Artisan::call('rulecraft:test', ['group' => 'approval', '--data' => $json])
            );
            $this->assertStringContainsString(
                'Provide a JSON context string with --data.',
                Artisan::output()
            );
        }
    }

    public function test_nested_context_is_available_to_expressions(): void
    {
        $this->group('approval', ['customer["country"] == "MM"']);
        $this->context = '{"customer": {"country": "MM"}}';
        $this->artisan('rulecraft:test', ['group' => 'approval', '--data' => $this->context])
            ->expectsOutput('PASS  approval [ALL]')
            ->expectsOutput('  PASS  condition-0')
            ->assertExitCode(0);
    }
}
