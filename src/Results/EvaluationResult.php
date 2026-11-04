<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Results;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Thuraaung\Rulecraft\Models\RuleGroup;

class EvaluationResult
{
    /** @var bool */
    private $passed;

    /** @var bool */
    private $disabled;

    /** @var Collection<int, RuleResult> */
    private $rules;

    /** @var Collection<string, EvaluationResult> */
    private $groups;

    /** @var ValidationException|null */
    private $validationError;

    /** @var RuleGroup|null */
    private $group;

    /**
     * @param  Collection<int, RuleResult>  $rules
     * @param  Collection<string, EvaluationResult>|null  $groups
     */
    public function __construct(
        bool $passed,
        Collection $rules,
        ?Collection $groups = null,
        ?RuleGroup $group = null,
        ?ValidationException $validationError = null,
        bool $disabled = false
    ) {
        $this->disabled = $disabled || ($group !== null && ! $group->enabled);
        $this->passed = $passed && $validationError === null && ! $this->disabled;
        $this->rules = $rules;
        $this->groups = $groups ?? collect();
        $this->group = $group;
        $this->validationError = $validationError;
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function disabled(): bool
    {
        return $this->disabled;
    }

    public function failed(): bool
    {
        return ! $this->passed;
    }

    /**
     * @return Collection<int, RuleResult>
     */
    public function rules(): Collection
    {
        return collect($this->rules->all());
    }

    public function group(): ?RuleGroup
    {
        return $this->group;
    }

    /** @return Collection<string, EvaluationResult> */
    public function groups(): Collection
    {
        return collect($this->groups->all());
    }

    /**
     * @return Collection<int, RuleResult>
     */
    public function allRules(): Collection
    {
        $rules = $this->rules();

        foreach ($this->groups as $group) {
            $rules = $rules->merge($group->allRules());
        }

        return $rules->values();
    }

    /**
     * @return Collection<int, RuleResult>
     */
    public function matchedRules(): Collection
    {
        return $this->allRules()->filter(function (RuleResult $result) {
            return $result->passed();
        })->values();
    }

    /**
     * @return Collection<int, RuleResult>
     */
    public function failedRules(): Collection
    {
        return $this->allRules()->filter(function (RuleResult $result) {
            return $result->failed();
        })->values();
    }

    public function failedMessage(string $separator = ' '): string
    {
        return $this->joinMessages($this->failedRules(), $separator);
    }

    public function passedMessage(string $separator = ' '): string
    {
        return $this->joinMessages($this->eligibleRules(), $separator);
    }

    /**
     * @return Collection<int, RuleResult>
     */
    public function eligibleRules(): Collection
    {
        if (! $this->passed()) {
            return collect();
        }

        $rules = $this->rules->filter(function (RuleResult $result) {
            return $result->passed();
        })->values();

        foreach ($this->groups as $group) {
            $rules = $rules->merge($group->eligibleRules());
        }

        return $rules->values();
    }

    /** @return array<string, array<int, string>> */
    public function validationErrors(): array
    {
        return $this->validationError === null ? [] : $this->validationError->errors();
    }

    public function hasErrors(): bool
    {
        return $this->errors()->isNotEmpty();
    }

    /**
     * @return Collection<int, \Throwable>
     */
    public function errors(): Collection
    {
        $errors = $this->rules->map(function (RuleResult $result) {
            return $result->error();
        })->filter()->values();

        if ($this->validationError !== null) {
            $errors->push($this->validationError);
        }

        foreach ($this->groups as $group) {
            $errors = $errors->merge($group->errors());
        }

        return $errors->values();
    }

    /** @param Collection<int, RuleResult> $rules */
    private function joinMessages(Collection $rules, string $separator): string
    {
        return $rules->map(function (RuleResult $result): ?string {
            return $result->message();
        })->filter(function (?string $message): bool {
            return $message !== null;
        })->implode($separator);
    }
}
