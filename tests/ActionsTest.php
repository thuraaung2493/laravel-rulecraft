<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Thuraaung\Rulecraft\Exceptions\InvalidActionHandlerException;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Tests\Fixtures\RecordingAction;

class ActionsTest extends TestCase
{
    /** @var RecordingAction */
    private $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new RecordingAction();
        $this->app->instance(RecordingAction::class, $this->handler);
        config(['rulecraft.actions' => ['record' => RecordingAction::class]]);
    }

    public function test_evaluate_skips_actions_and_run_receives_the_original_input(): void
    {
        $group = $this->group();
        $group->rules()->first()->update(
            ['action' => 'record', 'action_parameters' => ['output' => ['discount' => 10]]]
        );
        $data = ['amount' => 100, 'limit' => 200];

        $this->assertTrue(Rulecraft::group('approval')->evaluate($data)->passed());
        $this->assertSame([], $this->handler->calls);

        $execution = Rulecraft::group('approval')->run($data);
        $this->assertTrue($execution->successful());
        $this->assertSame($data, $this->handler->calls[0]['context']);
        $this->assertSame(
            ['amount' => 100, 'limit' => 200, 'discount' => 10],
            $execution->context()
        );
        $this->assertArrayNotHasKey('discount', $data);
    }

    public function test_failing_groups_execute_no_actions_even_when_a_rule_matches(): void
    {
        $group = $this->group('approval', ['true', 'false']);
        $group->rules()->first()->update(['action' => 'record']);

        $execution = Rulecraft::group('approval')->run([]);
        $this->assertTrue($execution->failed());
        $this->assertCount(0, $execution->actions());
        $this->assertSame([], $this->handler->calls);
    }

    public function test_any_group_runs_only_actions_for_matched_rules(): void
    {
        $group = $this->group('approval', ['false', 'true'], 'any');
        foreach ($group->rules as $rule) {
            $rule->update(['action' => 'record', 'action_parameters' => ['name' => $rule->name]]);
        }

        $execution = Rulecraft::group('approval')->run([]);
        $this->assertTrue($execution->successful());
        $this->assertCount(1, $this->handler->calls);
        $this->assertSame('condition-1', $this->handler->calls[0]['parameters']['name']);
    }

    public function test_actions_follow_priority_then_id_and_accumulate_context(): void
    {
        $group = $this->group('approval', []);
        foreach ([
            ['first', 10, ['key' => 'first']],
            ['second', 10, ['key' => 'second']],
            ['last', 0, []],
        ] as $entry) {
            $group->rules()->create([
                'name' => $entry[0], 'expression' => 'true', 'action' => 'record',
                'priority' => $entry[1], 'action_parameters' => ['output' => $entry[2]],
            ]);
        }

        $execution = Rulecraft::group('approval')->run(['key' => 'original']);
        $this->assertSame(
            ['first', 'second', 'last'],
            $execution->actions()->map(function ($outcome) {
                return $outcome->rule()->name;
            })->all()
        );
        $this->assertSame('first', $this->handler->calls[1]['context']['key']);
        $this->assertSame('second', $this->handler->calls[2]['context']['key']);
        $this->assertSame(['key' => 'second'], $execution->context());
    }

    public function test_failure_stops_actions_but_preserves_completed_outcomes(): void
    {
        $group = $this->group('approval', []);
        foreach ([
            ['output' => ['done' => true]],
            ['failure' => 'Unavailable'],
            ['output' => ['unexpected' => true]],
        ] as $parameters) {
            $group->rules()->create([
                'name' => 'action',
                'expression' => 'true',
                'action' => 'record',
                'action_parameters' => $parameters,
            ]);
        }

        $execution = Rulecraft::group('approval')->run([]);
        $this->assertTrue($execution->evaluation()->passed());
        $this->assertTrue($execution->failed());
        $this->assertCount(2, $execution->actions());
        $this->assertSame('Unavailable', $execution->actions()->last()->result()->error());
        $this->assertSame(['done' => true], $execution->context());
    }

    public function test_thrown_errors_are_preserved_as_action_failures(): void
    {
        $group = $this->group('approval', ['true']);
        $group->rules()->first()->update(
            ['action' => 'record', 'action_parameters' => ['throw' => 'Service unavailable']]
        );

        $execution = Rulecraft::group('approval')->run([]);
        $this->assertTrue($execution->failed());
        $this->assertInstanceOf(
            \RuntimeException::class,
            $execution->actions()->first()->exception()
        );
    }

    public function test_unknown_handlers_are_detected_before_any_action_runs(): void
    {
        $group = $this->group('approval', ['true', 'true']);
        $rules = $group->rules()->get();
        $rules[0]->update(['action' => 'record']);
        $rules[1]->update(['action' => 'missing']);

        try {
            Rulecraft::group('approval')->run([]);
            $this->fail('Expected an invalid handler exception.');
        } catch (InvalidActionHandlerException $error) {
            $this->assertSame([], $this->handler->calls);
        }
    }
}
