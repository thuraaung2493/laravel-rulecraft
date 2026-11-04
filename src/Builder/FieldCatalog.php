<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Builder;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Thuraaung\Rulecraft\Exceptions\InvalidPolicyException;

class FieldCatalog
{
    /** @var Repository */
    private $config;

    public function __construct(Repository $config)
    {
        $this->config = $config;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $fields = $this->config->get('rulecraft.builder.fields', []);

        if (! is_array($fields)) {
            throw new InvalidArgumentException('Builder fields must be an array.');
        }

        foreach ($fields as $name => &$field) {
            if (! is_string($name) || ! $this->validPath($name) || ! is_array($field)) {
                throw new InvalidArgumentException('Builder fields require valid context paths.');
            }

            $type = $field['type'] ?? 'string';
            $label = $field['label'] ?? $name;

            if (! in_array($type, ['string', 'integer', 'number', 'boolean'], true)
                || ! is_string($label) || trim($label) === '') {
                throw new InvalidArgumentException('Invalid builder field ['.$name.'].');
            }

            if (isset($field['options']) && (! is_array($field['options'])
                || array_values($field['options']) !== $field['options'])) {
                throw new InvalidArgumentException('Field options must be a list of values.');
            }

            foreach ($field['options'] ?? [] as $option) {
                $valid = ['string' => is_string($option), 'integer' => is_int($option),
                    'number' => is_int($option) || (is_float($option) && is_finite($option)),
                    'boolean' => is_bool($option)];
                if (! $valid[$type]) {
                    throw new InvalidArgumentException('Field options must match the field type.');
                }
            }
            $field['type'] = $type;
            $field['label'] = $label;
            $field['operators'] = $this->operators($type);
        }
        unset($field);

        return $fields;
    }

    /** @return array<string, mixed> */
    public function get(string $name): array
    {
        $fields = $this->all();

        if (! isset($fields[$name])) {
            throw new InvalidPolicyException('Unknown visual field ['.$name.'].');
        }

        return $fields[$name];
    }

    /** @return array<int, string> */
    public function operators(string $type): array
    {
        $operators = ['==', '!=', '===', '!==', 'in', 'not in'];

        return in_array($type, ['integer', 'number'], true)
            ? array_merge($operators, ['<', '<=', '>', '>=']) : $operators;
    }

    public function expression(string $path): string
    {
        if (! $this->validPath($path)) {
            throw new InvalidPolicyException('Invalid context field path ['.$path.'].');
        }

        $parts = explode('.', $path);
        $expression = array_shift($parts);

        foreach ($parts as $part) {
            $expression .= '["'.$part.'"]';
        }

        return $expression;
    }

    private function validPath(string $path): bool
    {
        $root = explode('.', $path)[0];

        $reserved = ['true', 'false', 'null', 'and', 'or', 'not', 'in', 'matches'];

        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z0-9_]+)*$/D', $path)
            && ! in_array($root, $reserved, true);
    }
}
