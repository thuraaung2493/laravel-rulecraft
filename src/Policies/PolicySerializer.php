<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Policies;

use JsonException;
use stdClass;
use Thuraaung\Rulecraft\Builder\DefinitionCompiler;
use Thuraaung\Rulecraft\Exceptions\InvalidPolicyException;
use Thuraaung\Rulecraft\Exceptions\RuleGroupNotFoundException;
use Thuraaung\Rulecraft\GroupHierarchy;
use Thuraaung\Rulecraft\Models\Rule;
use Thuraaung\Rulecraft\Models\RuleGroup;

class PolicySerializer
{
    /** @var PolicyValidator */
    private $validator;

    /** @var GroupHierarchy */
    private $hierarchy;

    /** @var DefinitionCompiler */
    private $definitions;

    public function __construct(
        PolicyValidator $validator,
        GroupHierarchy $hierarchy,
        DefinitionCompiler $definitions
    ) {
        $this->validator = $validator;
        $this->hierarchy = $hierarchy;
        $this->definitions = $definitions;
    }

    public function export(string $name): string
    {
        $group = RuleGroup::query()->where('name', $name)->first();

        if ($group === null) {
            throw new RuleGroupNotFoundException('Rule group ['.$name.'] does not exist.');
        }

        if ($group->parent_id !== null) {
            throw new InvalidPolicyException(
                'Export a root group to preserve the complete policy.'
            );
        }

        $tree = $this->serializeGroup($group);
        $version = $this->hasDefinitions($tree) ? 2 : 1;
        if ($version === 1) {
            $this->removeDefinitions($tree);
        }
        $document = (object) ['version' => $version, 'group' => $tree];
        $this->validator->validate($document);

        return json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    public function import(string $json): RuleGroup
    {
        try {
            $document = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidPolicyException(
                'Invalid policy JSON: '.$error->getMessage(), 0, $error
            );
        }

        if (! $document instanceof stdClass) {
            throw new InvalidPolicyException('Policy must be a JSON object.');
        }

        $names = $this->validator->validate($document);
        $connection = (new RuleGroup())->getConnection();

        return $connection->transaction(function () use ($document, $names): RuleGroup {
            foreach (array_chunk($names, 500) as $chunk) {
                $existing = RuleGroup::query()->whereIn('name', $chunk)->value('name');

                if ($existing !== null) {
                    throw new InvalidPolicyException(
                        'A group named ['.$existing.'] already exists.'
                    );
                }
            }

            return $this->persistGroup($document->group);
        });
    }

    /** @param array<int, string> $ancestors */
    private function serializeGroup(RuleGroup $group, array $ancestors = []): stdClass
    {
        $this->hierarchy->assertPath($ancestors, $group);
        $ancestors[] = (string) $group->getKey();
        $rules = [];

        foreach ($group->rules()->get() as $rule) {
            $parameters = $rule->getRawOriginal('action_parameters');
            $rules[] = (object) [
                'name' => $rule->name,
                'expression' => $rule->expression,
                'failure_message' => $rule->failure_message,
                'success_message' => $rule->success_message,
                'enabled' => $rule->enabled,
                'priority' => $rule->priority,
                'action' => $rule->action,
                'builder_definition' => $this->definition($rule),
                'action_parameters' => $parameters === null ? null
                    : json_decode($parameters, false, 512, JSON_THROW_ON_ERROR),
            ];
        }

        $children = [];

        foreach ($group->groups()->get() as $child) {
            $children[] = $this->serializeGroup($child, $ancestors);
        }

        return (object) [
            'name' => $group->name,
            'match' => $group->match,
            'enabled' => $group->enabled,
            'rules' => $rules,
            'groups' => $children,
        ];
    }

    private function definition(Rule $rule): ?stdClass
    {
        if ($rule->builder_definition === null) {
            return null;
        }
        try {
            $expression = $this->definitions->compile($rule->builder_definition, false);
            if ($expression === $rule->expression) {
                return json_decode($rule->getRawOriginal('builder_definition'));
            }
        } catch (InvalidPolicyException $error) {
            return null;
        }

        return null;
    }

    private function hasDefinitions(stdClass $group): bool
    {
        foreach ($group->rules as $rule) {
            if ($rule->builder_definition !== null) {
                return true;
            }
        }
        foreach ($group->groups as $child) {
            if ($this->hasDefinitions($child)) {
                return true;
            }
        }

        return false;
    }

    private function removeDefinitions(stdClass $group): void
    {
        foreach ($group->rules as $rule) {
            unset($rule->builder_definition);
        }
        foreach ($group->groups as $child) {
            $this->removeDefinitions($child);
        }
    }

    private function persistGroup(stdClass $node, ?RuleGroup $parent = null): RuleGroup
    {
        $group = new RuleGroup([
            'name' => $node->name,
            'match' => $node->match,
            'enabled' => $node->enabled,
        ]);

        if ($parent !== null) {
            $group->parent()->associate($parent);
        }

        if (! $group->save()) {
            throw new InvalidPolicyException('Saving group ['.$node->name.'] was canceled.');
        }

        foreach ($node->rules as $rule) {
            $model = $group->rules()->make(get_object_vars($rule));
            $attributes = $model->getAttributes();
            $attributes['action_parameters'] = $rule->action_parameters === null ? null
                : json_encode(
                    $rule->action_parameters,
                    JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
                );
            $model->setRawAttributes($attributes);

            if (! $model->save()) {
                throw new InvalidPolicyException('Saving rule ['.$rule->name.'] was canceled.');
            }
        }

        foreach ($node->groups as $child) {
            $this->persistGroup($child, $group);
        }

        return $group;
    }
}
