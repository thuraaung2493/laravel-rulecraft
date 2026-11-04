<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests\Fixtures;

use stdClass;

class BuilderData
{
    /** @return array<string, array<string, mixed>> */
    public static function fields(): array
    {
        return [
            'amount' => ['type' => 'number'], 'limit' => ['type' => 'number'],
            'branch' => ['type' => 'integer'], 'user_branch' => ['type' => 'integer'],
            'special' => ['type' => 'boolean'], 'zone' => ['type' => 'string'],
            'customer.country' => ['type' => 'string', 'options' => ['MM', 'TH']],
            'product_id' => ['type' => 'integer'],
        ];
    }

    public static function group(string $name = 'approval'): stdClass
    {
        $node = PolicyData::document($name)->group;
        $node->id = null;
        foreach ($node->rules as $rule) {
            $rule->id = null;
            $rule->builder_definition = null;
        }

        return $node;
    }

    /** @param mixed $value */
    public static function definition(string $field, string $operator, $value): stdClass
    {
        $fieldType = self::fields()[$field]['type'] ?? 'string';
        $sample = is_array($value) ? ($value[0] ?? null) : $value;
        $types = ['integer' => 'integer', 'double' => 'number', 'boolean' => 'boolean',
            'NULL' => 'null'];
        $valueType = $value === [] ? $fieldType : ($types[gettype($sample)] ?? 'string');
        if (in_array($valueType, ['integer', 'number'], true)) {
            $encode = function ($item) {
                return is_int($item) || is_float($item)
                    ? json_encode($item, JSON_PRESERVE_ZERO_FRACTION) : $item;
            };
            $value = is_array($value) ? array_map($encode, $value) : $encode($value);
        }

        return (object) ['source' => ['type' => 'field', 'field' => $field,
            'value_type' => $fieldType], 'operator' => $operator,
            'operand' => (object) [
                'type' => 'value', 'value_type' => $valueType, 'value' => $value,
            ]];
    }
}
