<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Builder;

use Thuraaung\Rulecraft\Exceptions\InvalidPolicyException;

class DefinitionCompiler
{
    /** @var FieldCatalog */
    private $fields;

    /** @var FunctionCatalog */
    private $functions;

    public function __construct(FieldCatalog $fields, FunctionCatalog $functions)
    {
        $this->fields = $fields;
        $this->functions = $functions;
    }

    /** @param array<string, mixed> $definition */
    public function compile(array $definition, bool $checkCatalog = true): string
    {
        $this->keys($definition, ['source', 'operator', 'operand']);
        if (! is_array($definition['source'])) {
            throw new InvalidPolicyException('Invalid visual source.');
        }
        [$left, $spec] = $this->source($definition['source'], $checkCatalog);
        $operator = $definition['operator'];
        if (! is_string($operator) || ! is_array($definition['operand'])
            || ! in_array($operator, $this->fields->operators($spec['type']), true)) {
            throw new InvalidPolicyException('Invalid visual comparison.');
        }

        $operand = $definition['operand'];
        $type = $operand['type'] ?? null;
        $membership = in_array($operator, ['in', 'not in'], true);

        if ($type === 'field') {
            $this->keys($operand, ['type', 'field']);

            if (! is_string($operand['field']) || $membership) {
                throw new InvalidPolicyException('Field operands require a scalar comparison.');
            }

            if ($checkCatalog) {
                $other = $this->fields->all()[$operand['field']] ?? null;
                if ($other !== null) {
                    $this->compatible($spec['type'], $other['type']);
                }
            }

            $right = $this->fields->expression($operand['field']);
        } elseif ($type === 'function') {
            if ($membership) {
                throw new InvalidPolicyException('Function operands require a scalar comparison.');
            }
            [$right, $other] = $this->source($operand, $checkCatalog);
            $this->compatible($spec['type'], $other['type']);
        } elseif ($type === 'value') {
            $this->keys($operand, ['type', 'value', 'value_type']);
            $value = $operand['value'];

            if ($membership) {
                if (! is_array($value) || array_values($value) !== $value) {
                    throw new InvalidPolicyException('Membership requires a JSON list.');
                }
                $items = [];
                foreach ($value as $item) {
                    $item = $this->decode($item, $operand['value_type']);
                    $this->value($item, $spec, false);
                    $items[] = $this->literal($item);
                }
                $right = '['.implode(', ', $items).']';
            } else {
                $value = $this->decode($value, $operand['value_type']);
                $this->value($value, $spec, ! in_array($operator, ['<', '<=', '>', '>='], true));
                $right = $this->literal($value);
            }
        } else {
            throw new InvalidPolicyException('Operand type must be field, function, or value.');
        }

        return $left.' '.$operator.' '.$right;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{string, array<string, mixed>}
     */
    private function source(array $source, bool $checkCatalog): array
    {
        if (($source['type'] ?? null) === 'field') {
            $this->keys($source, ['type', 'field', 'value_type']);
            if (! is_string($source['field'])) {
                throw new InvalidPolicyException('Invalid context field.');
            }
            $this->scalarType($source['value_type']);
            $spec = ['type' => $source['value_type']];
            if ($checkCatalog && isset($this->fields->all()[$source['field']])) {
                $spec = $this->fields->get($source['field']);
                if ($spec['type'] !== $source['value_type']) {
                    throw new InvalidPolicyException('Field type differs from its configuration.');
                }
            }

            return [$this->fields->expression($source['field']), $spec];
        }
        $this->keys($source, ['type', 'name', 'arguments', 'return_type']);
        if ($source['type'] !== 'function' || ! is_string($source['name'])
            || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $source['name'])
            || ! is_array($source['arguments'])) {
            throw new InvalidPolicyException('Invalid visual function call.');
        }
        $this->scalarType($source['return_type']);
        $spec = $checkCatalog ? $this->functions->get($source['name']) : null;
        if ($spec !== null && ($spec['return_type'] !== $source['return_type']
            || array_keys($spec['arguments']) !== array_keys($source['arguments']))) {
            throw new InvalidPolicyException('Function signature differs from its provider.');
        }
        $arguments = [];
        foreach ($source['arguments'] as $name => $argument) {
            if (! is_string($name) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)
                || ! is_array($argument)) {
                throw new InvalidPolicyException('Invalid function argument.');
            }
            $argumentSpec = $spec === null ? null : $spec['arguments'][$name];
            if (($argument['type'] ?? null) === 'field') {
                $this->keys($argument, ['type', 'field']);
                if (! is_string($argument['field'])) {
                    throw new InvalidPolicyException('Invalid argument context field.');
                }
                $arguments[] = $this->fields->expression($argument['field']);
                $fieldSpec = $checkCatalog ? ($this->fields->all()[$argument['field']] ?? null)
                    : null;
                if ($fieldSpec !== null && $argumentSpec !== null) {
                    $this->compatible($argumentSpec['type'], $fieldSpec['type']);
                }
            } else {
                $this->keys($argument, ['type', 'value', 'value_type']);
                if ($argument['type'] !== 'value') {
                    throw new InvalidPolicyException('Arguments must be fields or values.');
                }
                $value = $this->decode($argument['value'], $argument['value_type']);
                $this->value($value, $argumentSpec, false);
                $arguments[] = $this->literal($value);
            }
        }

        return [$source['name'].'('.implode(', ', $arguments).')',
            ['type' => $source['return_type']]];
    }

    private function compatible(string $left, string $right): void
    {
        if ($left !== $right && ! (in_array($left, ['integer', 'number'], true)
            && in_array($right, ['integer', 'number'], true))) {
            throw new InvalidPolicyException('Compared fields have incompatible types.');
        }
    }

    /** @param mixed $type */
    private function scalarType($type): void
    {
        if (! in_array($type, ['string', 'integer', 'number', 'boolean'], true)) {
            throw new InvalidPolicyException('Invalid visual scalar type.');
        }
    }

    /**
     * @param  mixed  $value
     * @param  mixed  $type
     * @return mixed
     */
    private function decode($value, $type)
    {
        if ($type === 'integer' && is_string($value)
            && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value)
            && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }
        if ($type === 'number' && is_string($value) && is_numeric($value)
            && is_finite((float) $value)) {
            return (float) $value;
        }
        if (($type === 'string' && is_string($value))
            || ($type === 'boolean' && is_bool($value)) || ($type === 'null' && $value === null)) {
            return $value;
        }

        throw new InvalidPolicyException('Invalid typed literal value.');
    }

    /** @param mixed $value */
    public function literal($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return '"'.addcslashes($value, "\\\"\0..\37\177").'"';
        }
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }

        throw new InvalidPolicyException('Visual values must be finite scalars or null.');
    }

    /**
     * @param  mixed  $value
     * @param  array<string, mixed>|null  $spec
     */
    private function value($value, ?array $spec, bool $nullable): void
    {
        $this->literal($value);

        if ($value === null && ! $nullable) {
            throw new InvalidPolicyException('This comparison requires a non-null value.');
        }
        if ($value === null || $spec === null) {
            return;
        }

        $valid = ['string' => is_string($value), 'integer' => is_int($value),
            'number' => is_int($value) || is_float($value), 'boolean' => is_bool($value)];

        $options = $spec['options'] ?? null;
        $allowed = $options === null || in_array($value, $options, $spec['type'] !== 'number');

        if (! $valid[$spec['type']] || ! $allowed) {
            throw new InvalidPolicyException('Value does not match the configured field.');
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $keys
     */
    private function keys(array $node, array $keys): void
    {
        $actual = array_keys($node);

        if (array_diff($keys, $actual) !== [] || array_diff($actual, $keys) !== []) {
            throw new InvalidPolicyException('Expected definition fields: '.implode(', ', $keys));
        }
    }
}
