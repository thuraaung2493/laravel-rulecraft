<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ContextValidator
{
    /** @var Repository */
    private $config;

    /** @var Factory */
    private $factory;

    public function __construct(Repository $config, Factory $factory)
    {
        $this->config = $config;
        $this->factory = $factory;
    }

    /** @param array<array-key, mixed> $data */
    public function validate(string $group, array $data): ?ValidationException
    {
        $schemas = $this->config->get('rulecraft.context_rules', []);

        if (! is_array($schemas)) {
            throw new InvalidArgumentException('rulecraft.context_rules must be an array.');
        }

        $rules = array_key_exists($group, $schemas) ? $schemas[$group] : [];

        if (! is_array($rules)) {
            throw new InvalidArgumentException('Context rules for ['.$group.'] must be an array.');
        }

        if ($rules === []) {
            return null;
        }

        $validator = $this->factory->make($data, $rules);

        return $validator->fails() ? new ValidationException($validator) : null;
    }
}
