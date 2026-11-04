<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Thuraaung\Rulecraft\Actions\ActionExecutor;
use Thuraaung\Rulecraft\Actions\ActionRegistry;
use Thuraaung\Rulecraft\Contracts\GrantResolver;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Permissions\PermissionEvaluation;
use Thuraaung\Rulecraft\Policies\PolicySerializer;

class Rulecraft
{
    /** @var ExpressionEvaluator */
    private $expressions;

    /** @var ActionExecutor */
    private $actions;

    /** @var Container */
    private $container;

    public function __construct(
        ExpressionEvaluator $expressions,
        ActionExecutor $actions,
        Container $container
    ) {
        $this->expressions = $expressions;
        $this->actions = $actions;
        $this->container = $container;
    }

    public function export(string $group): string
    {
        return $this->container->make(PolicySerializer::class)->export($group);
    }

    public function import(string $json): RuleGroup
    {
        return $this->container->make(PolicySerializer::class)->import($json);
    }

    public function registerAction(string $name, string $class): void
    {
        $this->container->make(ActionRegistry::class)->register($name, $class);
    }

    public function group(string $name): GroupEvaluation
    {
        if ($name === '') {
            throw new InvalidArgumentException('Group name cannot be empty.');
        }

        return new GroupEvaluation(
            $name,
            $this->expressions,
            $this->actions,
            $this->container->make(GroupHierarchy::class),
            $this->container->make(ContextValidator::class),
            $this->container->make(Dispatcher::class)
        );
    }

    /**
     * @param  mixed  $user
     */
    public function for($user): PermissionEvaluation
    {
        return new PermissionEvaluation(
            $user,
            $this->container->make(GrantResolver::class),
            $this,
            $this->container->make(Dispatcher::class)
        );
    }

    /**
     * @param  array<int, string>  $variables
     */
    public function validateExpression(string $expression, array $variables = []): void
    {
        $this->expressions->validate($expression, $variables);
    }
}
