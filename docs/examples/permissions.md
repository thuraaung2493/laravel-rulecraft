# Conditional permissions

[Documentation home](../../README.md) · [All examples](../examples.md)

Allow a Branch Manager to use Loan Approval only for their own branch and within their limit. This page covers setup, a real grant, its condition mapping, evaluation, and interpreting the result.

- [Prepare Spatie](#prepare-spatie-laravel-permission)
- [Create the grant and condition mapping](#create-the-grant-and-condition-mapping)
- [Read the permission decision](#read-the-permission-decision)
- [Show a permission in Blade](#show-a-conditional-permission-in-blade)
- [Suspend a conditional policy](#suspend-a-conditional-policy)
- [Mapping rules](#mapping-rules)
- [Multiple roles, guards, and teams](#multiple-roles-guards-and-teams)

## Prepare Spatie Laravel Permission

Install a Spatie version compatible with your Laravel/PHP application, publish its configuration/migrations, and migrate:

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

Rulecraft must also be installed and migrated. Add Spatie's `HasRoles` trait to your existing application's User model (keep its other application-specific fields and behavior):

```php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
}
```

Use your application's namespace; older Laravel apps may use `App\User`. The example assumes user ID 1 already exists and uses the `web` guard. Complete Spatie's guard/team configuration as required by your application. Spatie is optional for ordinary Rulecraft group evaluation; Rulecraft does not create Spatie's tables itself.

## Create the grant and condition mapping

Run this setup once, for example in a seeder. The code then evaluates a request with sample trusted context:

```php
use App\Models\User; // Use your application's model namespace (e.g. App\User on older apps).
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RolePermissionRule;
use Thuraaung\Rulecraft\Models\RuleGroup;

$user = User::findOrFail(1);
$role = Role::findOrCreate('Branch Manager', 'web');
$permission = Permission::findOrCreate('Loan Approval', 'web');
$role->givePermissionTo($permission);
$user->assignRole($role);

$group = RuleGroup::create(['name' => 'branch-manager-loan', 'match' => 'all']);
$group->rules()->createMany([
    ['name' => 'same-branch', 'expression' => 'loan_branch_id == user_branch_id'],
    ['name' => 'within-limit', 'expression' => 'loan_amount <= approval_limit'],
]);
$mapping = RolePermissionRule::assign($role, $permission, $group);

$result = Rulecraft::for($user)
    ->permission('Loan Approval')
    ->guard('web')
    ->evaluate([
        'loan_branch_id' => 1,
        'user_branch_id' => 1,
        'loan_amount' => 100000,
        'approval_limit' => 200000,
    ]);

$result->passed(); // true, given the membership/grant above
$result->evaluations()->get($mapping->getKey())->passed(); // true
$result->hasErrors(); // false
```

Creating a mapping alone never grants a permission. The user needs the role, and the role needs the permission, before Rulecraft evaluates that pair's condition group.

## Read the permission decision

The returned `PermissionResult` has these common methods:

| Method | Meaning |
| --- | --- |
| `passed()` / `failed()` | Whether at least one complete actual grant and mapped group allowed access, or its inverse. |
| `evaluations()` | Completed group evaluations keyed by mapping ID; each exposes rule/group diagnostics. |
| `hasErrors()` | Whether a mapped check encountered expression, missing-group, or hierarchy errors. |
| `errors()` | Collection of those `Throwable` objects. |

The sample passes without errors. Changing `loan_branch_id` to `2` or increasing the amount above the limit denies this grant without an error. Revoking its role membership or permission also denies it; when no other qualifying grants exist, `evaluations()` can be empty. An invalid expression or configured context-validation failure denies its group and records an error. Inspect `validationErrors()` on that mapping's group evaluation for field messages. An independently passing grant can still allow access while another grant contributes an error, so inspect errors separately.

```php
if ($result->passed()) {
    // The application may proceed with its authorized operation.
} else {
    // Deny this operation and inspect the diagnostics where appropriate.
}

foreach ($result->errors() as $error) {
    report($error);
}
```

These checks never execute action handlers and do not automatically replace Laravel Gate or Spatie's `can()`. Invoke the check explicitly at the point where conditional approval is required. Use server-derived branch IDs, loan amounts, limits, and approval flags rather than accepting authorization context directly from the client.

## Show a conditional permission in Blade

After setting up the role, grant, and mapping above, pass the same evaluation context to your view as `$context`:

```blade
@canRulecraft('Loan Approval', $context)
    <button type="submit">Approve loan</button>
@else
    <p>You cannot approve this loan.</p>
@endcanRulecraft
```

The condition uses the currently authenticated user and returns the permission evaluation's `passed()` value. Guests receive `false` without resolving grants. Context defaults to `[]` if omitted. To select a guard explicitly:

```blade
@canRulecraft('Loan Approval', $context, 'api')
    <button type="submit">Approve loan</button>
@endcanRulecraft
```

The optional third argument selects both the authentication guard used to retrieve the user and the permission guard used to resolve grants. When omitted, the current default authentication guard supplies the user and the normal Rulecraft/Spatie default permission guard applies. Failed conditions or missing grants hide the content; ordinary infrastructure/configuration exceptions retain the engine's behavior.

This directive never runs actions and leaves Laravel's existing `@can` unchanged. Enforce the permission decision again in your controller or service before performing the operation. Each directive evaluates separately; for repeated content, evaluate once and use `@if($result->passed())` with the result passed to the view.

## Suspend a conditional policy

Set the mapped root group's `enabled` attribute to `false` to deny that grant through Rulecraft. Its mapping and actual Spatie grant are preserved, so re-enabling the group restores normal checks. This does not revoke Spatie permissions or change Gate behavior. An independently passing role-permission grant can still allow access.

The mapped evaluation exposes `disabled()` and `failed()` as `true`, with no error solely from being disabled. Disabling a rule within an ALL policy also denies that policy; disabled conditions are not removed. See [policy states](policy-management.md#enable-or-disable-policies) for the complete ALL/ANY behavior.

## Mapping rules

`assign()` inserts or updates one mapping for the pair. All three models must already be saved, and the group must be a root. Remove its permission mappings before reparenting an assigned root. Permission checks reject mappings pointing to children or invalid hierarchies, so parent conditions cannot be bypassed by mapping a child directly. Role and permission identities include their model morph types and string keys, supporting morph aliases and integer or UUID keys up to 64 characters. Deleting a group cascades to its descendants, rules, and mappings.

## Multiple roles, guards, and teams

Each role-permission pair uses its own complete group. For example, a Head Office role can use a higher-limit policy for the same permission. Any passing actual grant allows access, but conditions and permission membership from different roles cannot be combined. Missing mappings and empty groups deny their grants. Direct user permissions and Gate super-admin shortcuts do not bypass this role-based check.

The initial Spatie integration resolves exact named role-permission grants; wildcard permissions are not expanded.

By default, Spatie selects the user's default guard. Override it per check with `->guard('api')`. Current Spatie team scope applies to freshly queried role membership, avoiding stale loaded relationships after a team switch or revocation. A mapping is per role-permission pair: shared roles reuse their mapping across teams; use distinct roles or team-aware conditions for differing policies.

For another permission system, implement `Thuraaung\Rulecraft\Contracts\GrantResolver` and set `rulecraft.permissions.resolver` to its class. Its `grants($user, $permission, $guard)` method returns actual `RolePermissionGrant` instances containing the eligible role and permission Eloquent models. Rulecraft performs the mapping lookup and group evaluation.

The optional [API reference](../api-reference.md#permissionresult) describes every return type and exception boundary.
