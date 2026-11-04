<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Builder;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;
use Thuraaung\Rulecraft\Contracts\BuilderExpressionProvider;
use Thuraaung\Rulecraft\Exceptions\InvalidPolicyException;

class FunctionCatalog
{
    /** @var Repository */
    private $config;

    /** @var Container */
    private $container;

    public function __construct(Repository $config, Container $container)
    {
        $this->config = $config;
        $this->container = $container;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $functions = [];
        $registered = [];
        foreach ($this->config->get('rulecraft.expression_providers', []) as $class) {
            $provider = $this->container->make($class);
            if (! $provider instanceof ExpressionFunctionProviderInterface) {
                throw new InvalidArgumentException('Invalid expression provider ['.$class.'].');
            }
            $names = [];
            foreach ($provider->getFunctions() as $function) {
                $name = $function->getName();
                if (isset($registered[$name])) {
                    throw new InvalidArgumentException(
                        'Duplicate expression function ['.$name.'].'
                    );
                }
                $registered[$name] = true;
                $names[] = $name;
            }
            if (! $provider instanceof BuilderExpressionProvider) {
                continue;
            }
            foreach ($provider->getBuilderFunctions() as $name => $spec) {
                if (! is_string($name) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)
                    || ! in_array($name, $names, true) || ! is_array($spec)) {
                    throw new InvalidArgumentException('Builder functions must be registered.');
                }
                $this->validate($spec);
                $functions[$name] = $spec;
            }
        }

        return $functions;
    }

    /** @return array<string, mixed> */
    public function get(string $name): array
    {
        $functions = $this->all();
        if (! isset($functions[$name])) {
            throw new InvalidPolicyException('Unknown visual function ['.$name.'].');
        }

        return $functions[$name];
    }

    /** @param array<string, mixed> $spec */
    private function validate(array $spec): void
    {
        $types = ['string', 'integer', 'number', 'boolean'];
        if (! is_string($spec['label'] ?? null) || trim($spec['label']) === ''
            || ! in_array($spec['return_type'] ?? null, $types, true)
            || ! is_array($spec['arguments'] ?? null)) {
            throw new InvalidArgumentException('Invalid builder function metadata.');
        }
        foreach ($spec['arguments'] as $name => $argument) {
            if (! is_string($name) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)
                || ! is_array($argument) || ! is_string($argument['label'] ?? null)
                || trim($argument['label']) === ''
                || ! in_array($argument['type'] ?? null, $types, true)) {
                throw new InvalidArgumentException('Invalid builder function argument.');
            }
        }
    }
}
