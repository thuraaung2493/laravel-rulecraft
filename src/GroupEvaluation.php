<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft;

use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Thuraaung\Rulecraft\Actions\ActionExecutor;
use Thuraaung\Rulecraft\Events\GroupEvaluated;
use Thuraaung\Rulecraft\Exceptions\RuleGroupNotFoundException;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Results\EvaluationResult;
use Thuraaung\Rulecraft\Results\ExecutionResult;

class GroupEvaluation
{
    /** @var string */
    private $name;

    /** @var ExpressionEvaluator */
    private $expressions;

    /** @var ActionExecutor */
    private $actions;

    /** @var GroupHierarchy */
    private $hierarchy;

    /** @var ContextValidator */
    private $contextValidator;

    /** @var Dispatcher */
    private $events;

    /** @var bool */
    private $stopOnFailure = false;

    public function __construct(
        string $name,
        ExpressionEvaluator $expressions,
        ActionExecutor $actions,
        GroupHierarchy $hierarchy,
        ContextValidator $contextValidator,
        Dispatcher $events
    ) {
        $this->name = $name;
        $this->expressions = $expressions;
        $this->actions = $actions;
        $this->hierarchy = $hierarchy;
        $this->contextValidator = $contextValidator;
        $this->events = $events;
    }

    public function stopOnFailure(): self
    {
        $evaluation = clone $this;
        $evaluation->stopOnFailure = true;

        return $evaluation;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function evaluate(array $data): EvaluationResult
    {
        $group = RuleGroup::query()->where('name', $this->name)->first();

        if ($group === null) {
            throw new RuleGroupNotFoundException('Rule group ['.$this->name.'] does not exist.');
        }

        if ($this->stopOnFailure && $group->match !== 'all') {
            throw new InvalidArgumentException('stopOnFailure is only supported for all groups.');
        }

        $ancestors = $this->hierarchy->ancestors($group);
        $disabledAncestor = $ancestors !== [] && RuleGroup::query()
            ->whereKey($ancestors)
            ->where('enabled', false)
            ->exists();

        $result = $disabledAncestor
            ? new EvaluationResult(false, collect(), collect(), $group, null, true)
            : (new GroupEvaluator($this->expressions, $this->hierarchy, $this->contextValidator))
                ->evaluate($group, $data, $ancestors, $this->stopOnFailure);

        $this->events->dispatch(new GroupEvaluated($this->name, $result, $data));

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function run(array $data): ExecutionResult
    {
        return $this->actions->execute($this->evaluate($data), $data);
    }
}
