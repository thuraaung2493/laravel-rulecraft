<?php

declare(strict_types=1);

use Thuraaung\Rulecraft\GroupHierarchy;
use Thuraaung\Rulecraft\Permissions\SpatieGrantResolver;

return [
    // Maximum group levels, including the root group.
    'max_depth' => GroupHierarchy::DEFAULT_MAX_DEPTH,

    'tables' => [
        'rules' => 'rulecraft_rules',
        'rule_groups' => 'rulecraft_rule_groups',
        'role_permission_rules' => 'rulecraft_role_permission_rules',
    ],

    // Or register handlers in a service provider's boot() with Rulecraft::registerAction().
    'actions' => [
        // 'apply_discount' => App\Actions\ApplyDiscount::class,
    ],

    // Optional Laravel validation rules, keyed by exact group name.
    'context_rules' => [
        // 'loan-approval' => ['loan_amount' => ['required', 'numeric']],
    ],

    'expression_providers' => [
        // Classes implementing Symfony's ExpressionFunctionProviderInterface.
    ],

    'builder' => [
        'enabled' => false,
        'theme' => 'custom',
        'stylesheet' => null,
        'route_prefix' => 'rulecraft/builder',
        'middleware' => ['web', 'auth'],
        'ability' => 'manage-rulecraft',
        // Optional dropdown catalog; empty enables free-text context fields.
        'fields' => [],
    ],

    'permissions' => [
        'resolver' => SpatieGrantResolver::class,
    ],
];
