# Configuration

[Documentation home](../README.md) · [API reference](api-reference.md) · [Examples](examples.md)

Publish `config/rulecraft.php` with `php artisan vendor:publish --tag=rulecraft-config`. This page lists every setting, its default, and the supported extension points. Basic installation and the first policy are in the [README](../README.md#getting-started).

- [Defaults](#defaults)
- [Table names](#table-names)
- [Group depth](#group-depth)
- [Context validation](#context-validation)
- [Actions](#actions)
- [Expression providers](#expression-providers)
- [Permission resolver](#permission-resolver)
- [Builder](#builder)
- [Evaluation listeners](#evaluation-listeners)
- [Configuration caching](#configuration-caching)

## Defaults

Keys below are relative to `rulecraft`:

| Key | Default | Purpose |
| --- | --- | --- |
| `tables.rules` | `rulecraft_rules` | Rule records. |
| `tables.rule_groups` | `rulecraft_rule_groups` | Root and child group records. |
| `tables.role_permission_rules` | `rulecraft_role_permission_rules` | Role-permission-to-root mappings. |
| `max_depth` | `10` | Maximum group levels, counting the root as level one. |
| `context_rules` | `[]` | Optional validation schemas keyed by exact group name. |
| `actions` | `[]` | Action names mapped to handler class names. |
| `expression_providers` | `[]` | Symfony expression function provider classes. |
| `builder` | Disabled; custom theme | Optional Blade editor and its field catalog. See all nested keys below. |
| `permissions.resolver` | `Thuraaung\Rulecraft\Permissions\SpatieGrantResolver::class` | Resolves actual role-permission grants. |

The default depth is defined by `GroupHierarchy::DEFAULT_MAX_DEPTH`. Source configuration is in `config/rulecraft.php`.

## Table names

Set every package table name independently before the first migration:

```php
'tables' => [
    'rules' => 'business_conditions',
    'rule_groups' => 'lending_policies',
    'role_permission_rules' => 'conditional_grants',
],
```

Models, relationships, foreign keys, schema creation, and rollback use these names. Connections and prefixes follow the host application's Laravel database configuration. Rulecraft does not rename or configure Spatie's own tables.

Changing configuration after migration does not rename existing tables. Use an explicit data-preserving migration and keep configuration aligned with the schema, including during rollback. JSON policy transfer is independent of table names; the destination uses its own configuration.

## Group depth

Set a positive integer:

```php
'max_depth' => 10,
```

The root counts as level one. The default allows at most ten group levels; rule leaves do not add a group level. Invalid values such as `0`, negative numbers, or numeric strings throw `InvalidArgumentException`.

Hierarchy guards reject cycles, missing parents, and subtree moves that exceed the limit. Evaluation and export also check stored hierarchies; imports validate their complete nesting before saving. Lowering the limit can make existing trees invalid. See [traversal and hierarchy examples](examples/rules.md#group-traversal-and-limits).

## Context validation

Declare Laravel validation rules keyed by the exact saved group name:

```php
'context_rules' => [
    'loan-approval' => [
        'loan_amount' => ['required', 'numeric', 'min:0'],
        'customer.country' => ['sometimes', 'string'],
        'items.*.quantity' => ['sometimes', 'integer', 'min:1'],
    ],
],
```

No schema or an empty schema leaves ordinary evaluation unchanged. Group keys are literal: `loans.approval` identifies that exact name, rather than a nested configuration path. Field paths support Laravel dot notation and wildcards.

Each visited group validates before its own expressions and descendants. Invalid context returns a failed `EvaluationResult` with a field-message map in `validationErrors()` and a collected `ValidationException`. It does not become an HTTP request-validation response. Parent `errors()` aggregates child failures; a sibling ANY match cannot override a validation error. Disabled groups skip validation.

Validation does not cast values, supply defaults, or remove extra fields. Laravel rules such as `integer` may accept numeric strings; use a custom rule when strict native PHP types are required. Keep validation rules compatible with your application's Laravel version. Use cacheable string/array definitions when caching configuration.

Context schemas stay in application configuration and are not included in exported policies. See [complete validation inputs and outcomes](examples/policy-management.md#validate-context).

## Actions

Map an action name to a class implementing `Thuraaung\Rulecraft\Contracts\Action`:

```php
'actions' => [
    'record_loan_decision' => App\Actions\RecordLoanDecision::class,
],
```

Alternatively, register it in an application or module service provider's `boot()` method:

```php
use App\Actions\RecordLoanDecision;
use Thuraaung\Rulecraft\Facades\Rulecraft;

public function boot(): void
{
    Rulecraft::registerAction('record_loan_decision', RecordLoanDecision::class);
}
```

Choose one location per action name. Config and dynamic registrations share one application-scoped registry. Duplicate names throw `InvalidActionHandlerException`, even if they point to the same class. Registration rejects blank names and invalid handler classes.

Registrations store class names. Laravel resolves handler constructors when preparing action execution, including constructor dependencies. `evaluate()`, permission checks, and policy transfer never resolve handlers. Provider registration lets each module own its handlers without expanding one config array; there is no automatic directory scanning.

A rule stores only the registered action name and its parameters. Import/export preserves those values without transporting handler code or registrations. The destination application must register the intended handler before calling `run()`.

See the [complete sample handler and execution behavior](examples/actions.md).

## Expression providers

List classes implementing Symfony's `ExpressionFunctionProviderInterface`:

```php
'expression_providers' => [
    App\Rules\CreditFunctions::class,
],
```

Laravel resolves providers through the container, so they can receive constructor dependencies. Their functions must be registered before expression parsing, validation, evaluation, or policy transfer. Imports/exports check expression syntax without executing business functions; unknown function names are rejected.

Rulecraft adds no general-purpose PHP function provider. Use named providers for application-specific functions, and keep the functions pure when evaluation must avoid side effects. See the [complete custom function example](expressions.md#custom-functions).

## Permission resolver

The default integration is optional Spatie Laravel Permission:

```php
'permissions' => [
    'resolver' => Thuraaung\Rulecraft\Permissions\SpatieGrantResolver::class,
],
```

Ordinary group evaluation does not require Spatie. For conditional permissions, install a compatible Spatie version and configure your application's user model, grants, guards, and teams. Rulecraft's group mappings do not create actual permission grants. See the [complete Spatie setup](examples/permissions.md).

To integrate another system, implement `Thuraaung\Rulecraft\Contracts\GrantResolver` and set `permissions.resolver` to that class. Laravel resolves it through the container. Its `grants($user, $permission, $guard)` method returns an iterable of `Permissions\RolePermissionGrant` objects containing the actual eligible role and permission Eloquent models. Rulecraft looks up and evaluates each exact pair's mapped root independently.

## Builder

| Key under `rulecraft.builder` | Default | Purpose |
| --- | --- | --- |
| `enabled` | `false` | Enables authorized component rendering and backend access. |
| `theme` | `custom` | `custom` or scoped `bootstrap` 5.3.8. |
| `stylesheet` | `null` | Optional local asset path, loaded after bundled styles. |
| `route_prefix` | `rulecraft/builder` | Prefix for named builder endpoints and bundled assets. |
| `middleware` | `['web', 'auth']` | Host middleware; package authorization is also always applied. |
| `ability` | `manage-rulecraft` | Laravel Gate ability required to manage all builder policies. |
| `fields` | `[]` | Optional catalog of field paths, labels, types, and values. Empty enables free-text context fields. |

Define the ability in your application's provider. Access is denied unless Laravel authorizes the configured ability. Route registration supports normal Laravel route caching; disabled access is checked at request time. Rebuild the route cache after changing prefix/middleware, and config cache after changing settings. Use the host's session authentication and CSRF middleware for writes; changing middleware is an application integration decision.

Fields accept types `string`, `integer`, `number`, and `boolean`. `label` defaults to the path; `type` defaults to `string`. Optional `options` is a list of native values matching that type. Paths use identifier-style roots and optional dot-separated array keys; dotted paths compile to array access. The visual catalog is application-managed and does not infer database columns or cast runtime data. Without a catalog, visual mode accepts free-text context paths and a field type. No database discovery or field registration is required. Field definitions store their own type, so they reopen visually even after the optional catalog is removed.

Providers registered in `expression_providers` may also implement `Contracts\BuilderExpressionProvider`. Its `getBuilderFunctions()` metadata supplies labels, ordered named arguments, and a scalar return type. The builder binds arguments automatically to context keys with the same names and displays their types in a read-only function signature. No separate function registration is needed. See the [complete visual provider example](examples/builder.md#custom-expression-providers).

Both themes support the documented `--rc-*` variables and `rc-*` customization classes. Stylesheets must be local asset paths; external URL schemes are rejected. Package assets load once, with content version hashes, through authorized routes. Publish markup overrides using `php artisan vendor:publish --tag=rulecraft-builder-views`.

See [complete configuration, component examples, and CSS customization](examples/builder.md).

## Evaluation listeners

Register `GroupEvaluated` and `PermissionEvaluated` listeners through Laravel's normal event registration in your provider's `boot()` method. No additional Rulecraft config key is required. The package uses the application's event dispatcher and leaves audit storage and metrics to your listeners.

See [complete listener examples](examples/events.md) and the [event property reference](api-reference.md#evaluation-events).

## Configuration caching

After changing configuration, rebuild the consuming application's cache if you use it:

```bash
php artisan config:cache
```

Class names and string/array validation rules can be cached normally. Closures and rule objects require application-specific care; do not assume they are cacheable. Provider-based actions register during every application boot, independently of the cached config array.

Enabled states, expressions, priorities, parameters, and group relationships are stored in the database. They are managed through Eloquent, rather than this config file. See [policy management examples](examples/policy-management.md).
