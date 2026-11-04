<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Results;

use Illuminate\Support\Collection;

class PermissionResult
{
    /** @var bool */
    private $passed;

    /** @var Collection<int, EvaluationResult> */
    private $evaluations;

    /** @var Collection<int, \Throwable> */
    private $errors;

    /**
     * @param  Collection<int, EvaluationResult>  $evaluations
     * @param  Collection<int, \Throwable>  $errors
     */
    public function __construct(bool $passed, Collection $evaluations, Collection $errors)
    {
        $this->passed = $passed;
        $this->evaluations = $evaluations;
        $this->errors = $errors;
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function failed(): bool
    {
        return ! $this->passed;
    }

    /**
     * @return Collection<int, EvaluationResult>
     */
    public function evaluations(): Collection
    {
        return collect($this->evaluations->all());
    }

    public function hasErrors(): bool
    {
        return $this->errors->isNotEmpty();
    }

    /**
     * @return Collection<int, \Throwable>
     */
    public function errors(): Collection
    {
        return collect($this->errors->all());
    }
}
