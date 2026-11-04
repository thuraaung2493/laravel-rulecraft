<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;
use Thuraaung\Rulecraft\Models\Rule;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Results\EvaluationResult;
use Thuraaung\Rulecraft\Results\RuleResult;

class GroupEvaluator
{
    /** @var ExpressionEvaluator */
    private $expressions;

    /** @var GroupHierarchy */
    private $hierarchy;

    /** @var ContextValidator */
    private $contextValidator;

    public function __construct(
        ExpressionEvaluator $expressions,
        GroupHierarchy $hierarchy,
        ContextValidator $contextValidator
    ) {
        $this->expressions = $expressions;
        $this->hierarchy = $hierarchy;
        $this->contextValidator = $contextValidator;
    }

    /**
     * @param  array<int, string>  $ancestors
     * @param  array<array-key, mixed>  $data
     */
    public function evaluate(
        RuleGroup $group,
        array $data,
        array $ancestors = [],
        bool $stopOnFailure = false
    ): EvaluationResult {
        $this->hierarchy->assertPath($ancestors, $group);
        $ancestors[] = (string) $group->getKey();

        if (! in_array($group->match, ['all', 'any'], true)) {
            throw new InvalidArgumentException('Group match must be all or any.');
        }

        if (! $group->enabled) {
            return new EvaluationResult(false, collect(), collect(), $group);
        }

        $validationError = $this->contextValidator->validate($group->name, $data);

        if ($validationError !== null) {
            return new EvaluationResult(false, collect(), collect(), $group, $validationError);
        }

        $rules = collect();
        $groups = collect();
        $stopHere = $stopOnFailure && $group->match === 'all';

        $directRules = $group->relationLoaded('rules') ? $group->rules : $group->rules()->get();
        foreach ($directRules as $rule) {
            $result = $this->evaluateRule($rule, $data);
            $rules->push($result);

            if ($stopHere && $result->failed()) {
                return $this->result($group, $rules, $groups);
            }
        }

        $children = $group->relationLoaded('groups') ? $group->groups : $group->groups()->get();
        foreach ($children as $child) {
            $result = $this->evaluate($child, $data, $ancestors, $stopOnFailure);
            $groups->put($child->name, $result);

            if ($stopHere && $result->failed()) {
                break;
            }
        }

        return $this->result($group, $rules, $groups);
    }

    /**
     * @param  Collection<int, RuleResult>  $rules
     * @param  Collection<string, EvaluationResult>  $groups
     */
    private function result(
        RuleGroup $group,
        Collection $rules,
        Collection $groups
    ): EvaluationResult {
        $results = $rules->merge($groups->values());
        $hasErrors = $results->contains(function ($result) {
            return $result instanceof RuleResult ? $result->error() !== null : $result->hasErrors();
        });
        $matches = $results->map(function ($result) {
            return $result->passed();
        });
        $passed = $matches->isNotEmpty() && ! $hasErrors
            && ($group->match === 'all' ? $matches->every(function ($matched) {
                return $matched;
            }) : $matches->contains(true));

        return new EvaluationResult($passed, $rules, $groups, $group);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function evaluateRule(Rule $rule, array $data): RuleResult
    {
        if (! $rule->enabled) {
            return new RuleResult($rule, false);
        }

        try {
            return new RuleResult($rule, $this->expressions->evaluate($rule->expression, $data));
        } catch (Throwable $error) {
            return new RuleResult($rule, false, $error);
        }
    }
}
