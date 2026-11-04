<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Policies;

use stdClass;
use Throwable;
use Thuraaung\Rulecraft\Builder\DefinitionCompiler;
use Thuraaung\Rulecraft\Exceptions\InvalidPolicyException;
use Thuraaung\Rulecraft\ExpressionEvaluator;
use Thuraaung\Rulecraft\GroupHierarchy;

class PolicyValidator
{
    /** @var ExpressionEvaluator */
    private $expressions;

    /** @var GroupHierarchy */
    private $hierarchy;

    /** @var DefinitionCompiler */
    private $definitions;

    public function __construct(
        ExpressionEvaluator $expressions,
        GroupHierarchy $hierarchy,
        DefinitionCompiler $definitions
    ) {
        $this->expressions = $expressions;
        $this->hierarchy = $hierarchy;
        $this->definitions = $definitions;
    }

    /** @return array<int, string> */
    public function validate(stdClass $document): array
    {
        $this->keys($document, ['version', 'group'], 'policy');

        if (! in_array($document->version, [1, 2], true)) {
            throw new InvalidPolicyException(
                'Unsupported policy version; expected integer 1 or 2.'
            );
        }

        $names = [];
        $this->group($document->group, 'group', 1, $names, $document->version);

        return $names;
    }

    /**
     * @param  mixed  $group
     * @param  array<int, string>  $names
     */
    private function group(
        $group,
        string $path,
        int $depth,
        array &$names,
        int $version
    ): void {
        if (! $group instanceof stdClass) {
            throw new InvalidPolicyException($path.' must be an object.');
        }

        if ($depth > $this->hierarchy->maxDepth()) {
            throw new InvalidPolicyException($path.' exceeds rulecraft.max_depth.');
        }

        $this->keys($group, ['name', 'match', 'enabled', 'rules', 'groups'], $path);
        $name = $this->name($group->name, $path.'.name');

        if (in_array($name, $names, true)) {
            throw new InvalidPolicyException('Duplicate group name ['.$name.'] in policy.');
        }

        $names[] = $name;

        if (! in_array($group->match, ['all', 'any'], true)) {
            throw new InvalidPolicyException($path.'.match must be all or any.');
        }

        if (! is_bool($group->enabled)) {
            throw new InvalidPolicyException($path.'.enabled must be a boolean.');
        }

        if (! is_array($group->rules) || ! is_array($group->groups)) {
            throw new InvalidPolicyException($path.'.rules and .groups must be JSON arrays.');
        }

        foreach ($group->rules as $index => $rule) {
            $this->rule($rule, $path.'.rules.'.$index, $version);
        }

        foreach ($group->groups as $index => $child) {
            $this->group($child, $path.'.groups.'.$index, $depth + 1, $names, $version);
        }
    }

    /** @param mixed $rule */
    private function rule($rule, string $path, int $version): void
    {
        if (! $rule instanceof stdClass) {
            throw new InvalidPolicyException($path.' must be an object.');
        }

        $rule->failure_message = $rule->failure_message ?? null;
        $rule->success_message = $rule->success_message ?? null;
        $keys = ['name', 'expression', 'failure_message', 'success_message', 'enabled', 'priority',
            'action', 'action_parameters'];

        if ($version === 2) {
            $keys[] = 'builder_definition';
        }
        $this->keys($rule, $keys, $path);

        if ($version === 2 && $rule->builder_definition !== null) {
            if (! $rule->builder_definition instanceof stdClass) {
                throw new InvalidPolicyException($path.'.builder_definition must be an object.');
            }
            $definitionJson = json_encode(
                $rule->builder_definition, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
            $definition = json_decode($definitionJson, true);
            if ($this->definitions->compile($definition, false) !== $rule->expression) {
                throw new InvalidPolicyException(
                    $path.'.builder_definition does not match expression.'
                );
            }
        }
        $this->name($rule->name, $path.'.name');

        foreach (['failure_message', 'success_message'] as $field) {
            if ($rule->$field !== null && ! is_string($rule->$field)) {
                throw new InvalidPolicyException($path.'.'.$field.' must be a string or null.');
            }
        }

        if (! is_string($rule->expression) || trim($rule->expression) === '') {
            throw new InvalidPolicyException($path.'.expression must be a nonempty string.');
        }

        if (! is_bool($rule->enabled)) {
            throw new InvalidPolicyException($path.'.enabled must be a boolean.');
        }

        if (! is_int($rule->priority)
            || $rule->priority < -2147483648 || $rule->priority > 2147483647) {
            throw new InvalidPolicyException($path.'.priority must be a signed 32-bit integer.');
        }

        if ($rule->action !== null
            && (! is_string($rule->action) || mb_strlen($rule->action) > 191)) {
            throw new InvalidPolicyException(
                $path.'.action must be null or a string of at most 191 characters.'
            );
        }

        $parameters = $rule->action_parameters;

        if ($parameters !== null && ! is_array($parameters) && ! $parameters instanceof stdClass) {
            throw new InvalidPolicyException(
                $path.'.action_parameters must be null, an object, or an array.'
            );
        }

        try {
            $this->expressions->lint($rule->expression);
        } catch (Throwable $error) {
            throw new InvalidPolicyException($path.'.expression: '.$error->getMessage(), 0, $error);
        }
    }

    /** @param mixed $name */
    private function name($name, string $path): string
    {
        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 191) {
            throw new InvalidPolicyException(
                $path.' must be a nonempty string of at most 191 characters.'
            );
        }

        return $name;
    }

    /** @param array<int, string> $keys */
    private function keys(stdClass $node, array $keys, string $path): void
    {
        $actual = array_keys(get_object_vars($node));

        if (array_diff($keys, $actual) !== [] || array_diff($actual, $keys) !== []) {
            throw new InvalidPolicyException(
                $path.' must contain exactly: '.implode(', ', $keys).'.'
            );
        }
    }
}
