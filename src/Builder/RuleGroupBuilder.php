<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Builder;

use Illuminate\Database\Eloquent\Collection;
use stdClass;
use Thuraaung\Rulecraft\Exceptions\InvalidPolicyException;
use Thuraaung\Rulecraft\Exceptions\RuleGroupNotFoundException;
use Thuraaung\Rulecraft\Exceptions\StalePolicyException;
use Thuraaung\Rulecraft\GroupEvaluator;
use Thuraaung\Rulecraft\GroupHierarchy;
use Thuraaung\Rulecraft\Models\Rule;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Policies\PolicyValidator;
use Thuraaung\Rulecraft\Results\EvaluationResult;

class RuleGroupBuilder
{
    /** @var DefinitionCompiler */
    private $definitions;

    /** @var PolicyValidator */
    private $validator;

    /** @var GroupHierarchy */
    private $hierarchy;

    /** @var GroupEvaluator */
    private $evaluator;

    public function __construct(
        DefinitionCompiler $definitions,
        PolicyValidator $validator,
        GroupHierarchy $hierarchy,
        GroupEvaluator $evaluator
    ) {
        $this->definitions = $definitions;
        $this->validator = $validator;
        $this->hierarchy = $hierarchy;
        $this->evaluator = $evaluator;
    }

    /** @return array{group: stdClass, revision: string} */
    public function load(string $name): array
    {
        $group = RuleGroup::query()->where('name', $name)->first();
        if ($group === null) {
            throw new RuleGroupNotFoundException('Rule group ['.$name.'] does not exist.');
        }
        if ($group->parent_id !== null) {
            throw new InvalidPolicyException('The builder edits complete root policies.');
        }
        $tree = $this->serialize($group);

        return ['group' => $tree, 'revision' => $this->revision($tree)];
    }

    public function validate(stdClass $draft): stdClass
    {
        $node = json_decode(json_encode($draft, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $this->normalize($node);
        $policyJson = json_encode($node, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $policy = json_decode($policyJson);
        $this->stripIds($policy);
        $this->validator->validate((object) ['version' => 2, 'group' => $policy]);

        return $node;
    }

    /** @param array<array-key, mixed> $context */
    public function preview(stdClass $draft, array $context): EvaluationResult
    {
        return $this->evaluator->evaluate($this->models($this->validate($draft)), $context);
    }

    public function save(stdClass $draft, ?string $revision = null): RuleGroup
    {
        $node = $this->validate($draft);

        return (new RuleGroup())->getConnection()->transaction(function () use (
            $node,
            $revision
        ): RuleGroup {
            $groups = [];
            $rules = [];
            if ($node->id !== null) {
                $root = RuleGroup::query()->whereKey($node->id)->lockForUpdate()->first();
                if ($root === null || $root->parent_id !== null) {
                    throw new InvalidPolicyException('Selected root policy does not exist.');
                }
                if ($revision === null
                    || ! hash_equals($this->revision($this->serialize($root)), $revision)) {
                    throw new StalePolicyException('Policy changed. Reload before saving.');
                }
                $this->collect($root, $groups, $rules);
            }
            $seenGroups = [];
            $seenRules = [];
            $this->checkIds($node, null, $groups, $rules, $seenGroups, $seenRules);
            $names = $this->names($node);
            foreach (array_chunk($names, 500) as $chunk) {
                $query = RuleGroup::query()->whereIn('name', $chunk);
                if ($groups !== []) {
                    $query->whereNotIn('id', array_keys($groups));
                }
                if ($query->exists()) {
                    throw new InvalidPolicyException('A policy group name is already in use.');
                }
            }
            foreach ($rules as $id => $rule) {
                if (! isset($seenRules[$id]) && $rule->delete() === false) {
                    throw new InvalidPolicyException('Deleting a rule was canceled.');
                }
            }
            foreach (array_reverse($groups, true) as $id => $group) {
                if (! isset($seenGroups[$id]) && $group->delete() === false) {
                    throw new InvalidPolicyException('Deleting a group was canceled.');
                }
            }

            return $this->persist($node, null, $groups, $rules);
        });
    }

    private function normalize(stdClass $node, int $depth = 1): void
    {
        if ($depth > $this->hierarchy->maxDepth()) {
            throw new InvalidPolicyException('Draft exceeds rulecraft.max_depth.');
        }
        $this->keys($node, ['id', 'name', 'match', 'enabled', 'rules', 'groups']);
        $node->id = $this->id($node->id);
        if (! is_array($node->rules) || ! is_array($node->groups)) {
            throw new InvalidPolicyException('Rules and groups must be JSON arrays.');
        }
        foreach ($node->rules as $rule) {
            if (! $rule instanceof stdClass) {
                throw new InvalidPolicyException('Each rule must be an object.');
            }
            $rule->failure_message = $rule->failure_message ?? null;
            $rule->success_message = $rule->success_message ?? null;
            $keys = ['id', 'name', 'expression', 'failure_message', 'success_message',
                'enabled', 'priority', 'action', 'action_parameters', 'builder_definition'];
            if (property_exists($rule, 'action_parameters_json')) {
                $keys[] = 'action_parameters_json';
            }
            $this->keys($rule, $keys);
            if (property_exists($rule, 'action_parameters_json')) {
                if (! is_string($rule->action_parameters_json)) {
                    throw new InvalidPolicyException('Action parameter JSON must be a string.');
                }
                $rule->action_parameters = json_decode(
                    $rule->action_parameters_json, false, 512, JSON_THROW_ON_ERROR
                );
            }
            $rule->action_parameters_json = json_encode(
                $rule->action_parameters, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
            $rule->id = $this->id($rule->id);
            if ($rule->builder_definition !== null) {
                if (! $rule->builder_definition instanceof stdClass) {
                    throw new InvalidPolicyException('Visual definitions must be objects.');
                }
                $definitionJson = json_encode(
                    $rule->builder_definition, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
                );
                $definition = json_decode($definitionJson, true);
                $rule->expression = $this->definitions->compile($definition);
            }
        }
        foreach ($node->groups as $child) {
            if (! $child instanceof stdClass) {
                throw new InvalidPolicyException('Each group must be an object.');
            }
            $this->normalize($child, $depth + 1);
        }
    }

    private function stripIds(stdClass $node): void
    {
        unset($node->id);
        foreach ($node->rules as $rule) {
            unset($rule->id, $rule->action_parameters_json);
        }
        foreach ($node->groups as $child) {
            $this->stripIds($child);
        }
    }

    private function models(stdClass $node): RuleGroup
    {
        $group = new RuleGroup(['name' => $node->name, 'match' => $node->match,
            'enabled' => $node->enabled]);
        $rules = [];
        $nextId = 0;
        foreach ($node->rules as $rule) {
            $nextId = max($nextId, (int) ($rule->id ?? 0));
        }
        foreach ($node->rules as $rule) {
            $attributes = get_object_vars($rule);
            unset($attributes['id'], $attributes['action_parameters_json']);
            $model = new Rule($attributes);
            $model->setAttribute('id', $rule->id === null ? ++$nextId : (int) $rule->id);
            $rules[] = $model;
        }
        usort($rules, function (Rule $first, Rule $second): int {
            return ($second->priority <=> $first->priority) ?: ($first->id <=> $second->id);
        });
        $children = [];
        foreach ($node->groups as $child) {
            $children[] = $this->models($child);
        }
        $group->setRelation('rules', new Collection($rules));
        $group->setRelation('groups', new Collection($children));

        return $group;
    }

    /** @param array<int, string> $ancestors */
    private function serialize(RuleGroup $group, array $ancestors = []): stdClass
    {
        $this->hierarchy->assertPath($ancestors, $group);
        $ancestors[] = (string) $group->getKey();
        $rules = [];
        foreach ($group->rules()->get() as $rule) {
            $definition = $rule->builder_definition;
            if ($definition !== null) {
                try {
                    if ($this->definitions->compile($definition) !== $rule->expression) {
                        $definition = null;
                    }
                } catch (InvalidPolicyException $error) {
                    $definition = null;
                }
            }
            $parameters = $rule->getRawOriginal('action_parameters');
            $rules[] = (object) ['id' => (string) $rule->getKey(), 'name' => $rule->name,
                'expression' => $rule->expression, 'enabled' => $rule->enabled,
                'failure_message' => $rule->failure_message,
                'success_message' => $rule->success_message,
                'priority' => $rule->priority, 'action' => $rule->action,
                'action_parameters' => $parameters === null ? null : json_decode($parameters),
                'action_parameters_json' => $parameters ?? 'null',
                'builder_definition' => $definition === null ? null : (object) $definition];
        }
        $children = [];
        foreach ($group->groups()->get() as $child) {
            $children[] = $this->serialize($child, $ancestors);
        }

        return (object) ['id' => (string) $group->getKey(), 'name' => $group->name,
            'match' => $group->match, 'enabled' => $group->enabled,
            'rules' => $rules, 'groups' => $children];
    }

    private function revision(stdClass $tree): string
    {
        $json = json_encode($tree, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return hash('sha256', $json);
    }

    /**
     * @param  array<int|string, RuleGroup>  $groups
     * @param  array<int|string, Rule>  $rules
     */
    private function collect(RuleGroup $group, array &$groups, array &$rules): void
    {
        $groups[(string) $group->getKey()] = $group;
        foreach ($group->rules()->get() as $rule) {
            $rules[(string) $rule->getKey()] = $rule;
        }
        foreach ($group->groups()->get() as $child) {
            $this->collect($child, $groups, $rules);
        }
    }

    /**
     * @param  array<int|string, RuleGroup>  $groups
     * @param  array<int|string, Rule>  $rules
     * @param  array<int|string, bool>  $seenGroups
     * @param  array<int|string, bool>  $seenRules
     */
    private function checkIds(
        stdClass $node,
        ?string $parent,
        array $groups,
        array $rules,
        array &$seenGroups,
        array &$seenRules
    ): void {
        if ($node->id !== null) {
            $existing = $groups[$node->id] ?? null;
            $parentId = $existing !== null && $existing->parent_id !== null
                ? (string) $existing->parent_id : null;
            if ($existing === null || isset($seenGroups[$node->id]) || $parentId !== $parent) {
                throw new InvalidPolicyException('Group IDs must belong to their original parent.');
            }
            $seenGroups[$node->id] = true;
        }
        foreach ($node->rules as $rule) {
            if ($rule->id !== null) {
                $existing = $rules[$rule->id] ?? null;
                if ($existing === null || isset($seenRules[$rule->id])
                    || (string) $existing->rule_group_id !== $node->id) {
                    throw new InvalidPolicyException(
                        'Rule IDs must belong to their original group.'
                    );
                }
                $seenRules[$rule->id] = true;
            }
        }
        foreach ($node->groups as $child) {
            $this->checkIds($child, $node->id, $groups, $rules, $seenGroups, $seenRules);
        }
    }

    /** @return array<int, string> */
    private function names(stdClass $node): array
    {
        $names = [$node->name];
        foreach ($node->groups as $child) {
            $names = array_merge($names, $this->names($child));
        }

        return $names;
    }

    /**
     * @param  array<int|string, RuleGroup>  $groups
     * @param  array<int|string, Rule>  $rules
     */
    private function persist(
        stdClass $node,
        ?RuleGroup $parent,
        array $groups,
        array $rules
    ): RuleGroup {
        $group = $node->id === null ? new RuleGroup() : $groups[$node->id];
        $group->fill(['name' => $node->name, 'match' => $node->match, 'enabled' => $node->enabled]);
        if ($parent !== null) {
            $group->parent()->associate($parent);
        }
        if (! $group->save()) {
            throw new InvalidPolicyException('Saving group ['.$node->name.'] was canceled.');
        }
        foreach ($node->rules as $rule) {
            $model = $rule->id === null ? new Rule() : $rules[$rule->id];
            $attributes = get_object_vars($rule);
            unset($attributes['id'], $attributes['action_parameters_json']);
            $model->fill($attributes);
            $model->group()->associate($group);
            $raw = $model->getAttributes();
            $raw['action_parameters'] = $rule->action_parameters === null ? null
                : json_encode($rule->action_parameters,
                    JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $model->setRawAttributes($raw);
            if (! $model->save()) {
                throw new InvalidPolicyException('Saving rule ['.$rule->name.'] was canceled.');
            }
        }
        foreach ($node->groups as $child) {
            $this->persist($child, $group, $groups, $rules);
        }

        return $group;
    }

    /** @param mixed $id */
    private function id($id): ?string
    {
        if ($id === null) {
            return null;
        }
        if ((! is_string($id) && ! is_int($id)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $id)) {
            throw new InvalidPolicyException('IDs must be positive integer strings or null.');
        }

        return (string) $id;
    }

    /** @param array<int, string> $keys */
    private function keys(stdClass $node, array $keys): void
    {
        $actual = array_keys(get_object_vars($node));
        if (array_diff($keys, $actual) !== [] || array_diff($actual, $keys) !== []) {
            throw new InvalidPolicyException('Expected fields: '.implode(', ', $keys));
        }
    }
}
