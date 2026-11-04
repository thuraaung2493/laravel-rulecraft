<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Actions;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Throwable;
use Thuraaung\Rulecraft\Contracts\Action;
use Thuraaung\Rulecraft\Exceptions\InvalidActionHandlerException;

class ActionRegistry
{
    /** @var Container */
    private $container;

    /** @var Repository */
    private $config;

    /** @var array<string, class-string<Action>> */
    private $actions = [];

    public function __construct(Container $container, Repository $config)
    {
        $this->container = $container;
        $this->config = $config;
    }

    public function register(string $name, string $class): void
    {
        if (trim($name) === '') {
            throw new InvalidActionHandlerException('Action name cannot be empty.');
        }

        $configured = $this->config->get('rulecraft.actions', []);

        if (array_key_exists($name, $configured) || array_key_exists($name, $this->actions)) {
            throw new InvalidActionHandlerException('Action ['.$name.'] is already registered.');
        }

        $this->validateHandler($name, $class);
        $this->actions[$name] = $class;
    }

    /** @return array<int, string> */
    public function names(): array
    {
        $configured = $this->config->get('rulecraft.actions', []);
        $names = array_map('strval', array_unique(array_merge(
            array_keys($configured),
            array_keys($this->actions)
        )));
        sort($names, SORT_STRING);

        return $names;
    }

    public function resolve(string $name): Action
    {
        $configured = $this->config->get('rulecraft.actions', []);

        if (array_key_exists($name, $configured) && array_key_exists($name, $this->actions)) {
            throw new InvalidActionHandlerException('Action ['.$name.'] is already registered.');
        }

        $class = $this->actions[$name] ?? $configured[$name] ?? null;
        $this->validateHandler($name, $class);

        try {
            $handler = $this->container->make($class);
        } catch (Throwable $error) {
            throw new InvalidActionHandlerException(
                'Cannot resolve action ['.$name.'].',
                0,
                $error
            );
        }

        if (! $handler instanceof Action) {
            throw new InvalidActionHandlerException(
                'Action ['.$name.'] must implement '.Action::class.'.'
            );
        }

        return $handler;
    }

    /**
     * @param  mixed  $class
     *
     * @phpstan-assert class-string<Action> $class
     */
    private function validateHandler(string $name, $class): void
    {
        if (! is_string($class) || ! is_a($class, Action::class, true)) {
            throw new InvalidActionHandlerException(
                'No valid handler registered for action ['.$name.'].'
            );
        }
    }
}
