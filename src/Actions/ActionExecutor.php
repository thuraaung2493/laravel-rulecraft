<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Actions;

use Throwable;
use Thuraaung\Rulecraft\Contracts\Action;
use Thuraaung\Rulecraft\Models\Rule;
use Thuraaung\Rulecraft\Results\EvaluationResult;
use Thuraaung\Rulecraft\Results\ExecutionResult;

class ActionExecutor
{
    /** @var ActionRegistry */
    private $registry;

    public function __construct(ActionRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function execute(EvaluationResult $evaluation, array $context): ExecutionResult
    {
        $outcomes = collect();

        if (! $evaluation->passed()) {
            return new ExecutionResult($evaluation, $outcomes, $context);
        }

        // Validate all registrations before the first handler can cause side effects.
        $handlers = $this->resolveHandlers($evaluation);

        foreach ($handlers as $entry) {
            [$rule, $handler] = $entry;
            $outcome = $this->invoke($rule, $handler, $context);
            $outcomes->push($outcome);

            if ($outcome->result()->failed()) {
                break;
            }

            $context = array_replace($context, $outcome->result()->data());
        }

        return new ExecutionResult($evaluation, $outcomes, $context);
    }

    /**
     * @return array<int, array{Rule, Action}>
     */
    private function resolveHandlers(EvaluationResult $evaluation): array
    {
        $handlers = [];

        foreach ($evaluation->eligibleRules() as $result) {
            $rule = $result->rule();

            if ($rule->action === null || $rule->action === '') {
                continue;
            }

            $handlers[] = [$rule, $this->registry->resolve($rule->action)];
        }

        return $handlers;
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    private function invoke(Rule $rule, Action $handler, array $context): ActionOutcome
    {
        try {
            return new ActionOutcome(
                $rule,
                $handler->handle($rule->action_parameters ?? [], $context)
            );
        } catch (Throwable $error) {
            return new ActionOutcome($rule, ActionResult::failure($error->getMessage()), $error);
        }
    }
}
