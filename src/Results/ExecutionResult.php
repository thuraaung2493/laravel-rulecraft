<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Results;

use Illuminate\Support\Collection;
use Thuraaung\Rulecraft\Actions\ActionOutcome;

class ExecutionResult
{
    /** @var EvaluationResult */
    private $evaluation;

    /** @var Collection<int, ActionOutcome> */
    private $actions;

    /** @var array<array-key, mixed> */
    private $context;

    /**
     * @param  Collection<int, ActionOutcome>  $actions
     * @param  array<array-key, mixed>  $context
     */
    public function __construct(EvaluationResult $evaluation, Collection $actions, array $context)
    {
        $this->evaluation = $evaluation;
        $this->actions = $actions;
        $this->context = $context;
    }

    public function successful(): bool
    {
        return $this->evaluation->passed()
            && $this->actions->every(function (ActionOutcome $outcome) {
                return $outcome->result()->successful();
            });
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function evaluation(): EvaluationResult
    {
        return $this->evaluation;
    }

    /**
     * @return Collection<int, ActionOutcome>
     */
    public function actions(): Collection
    {
        return collect($this->actions->all());
    }

    /**
     * @return array<array-key, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
