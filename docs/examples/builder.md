# Rulecraft Builder

[Documentation home](../../README.md) · [All examples](../examples.md) · [Configuration](../configuration.md#builder)

Embed a Blade editor in your application's own page. It creates and edits complete root policies, previews unsaved conditions, and saves changes without executing action handlers. Assets are bundled; consuming applications need no React, Inertia, Tailwind, or frontend build step.

- [Enable and authorize](#enable-and-authorize)
- [Embed the component](#embed-the-component)
- [Build the loan approval example](#build-the-loan-approval-example)
- [Free-input fields](#free-input-fields)
- [Custom expression providers](#custom-expression-providers)
- [Expression mode](#expression-mode)
- [Styles and CSS overrides](#styles-and-css-overrides)
- [Saving and conflict handling](#saving-and-conflict-handling)
- [Preview and JSON transfer](#preview-and-json-transfer)

## Enable and authorize

Install/migrate Rulecraft using the [README](../../README.md#install-and-migrate). The builder's nullable `builder_definition` rule column is included in the single initial migration. This development package uses one initial migration; existing development databases must contain the current schema.

Enable the builder and optionally configure a field catalog in `config/rulecraft.php`:

```php
'builder' => [
    'enabled' => true,
    'theme' => 'custom',
    'stylesheet' => 'css/rulecraft-overrides.css', // Optional; use null without overrides.
    'route_prefix' => 'rulecraft/builder',
    'middleware' => ['web', 'auth'],
    'ability' => 'manage-rulecraft',
    'fields' => [
        'loan_branch_id' => ['label' => 'Loan branch', 'type' => 'integer'],
        'user_branch_id' => ['label' => 'User branch', 'type' => 'integer'],
        'loan_amount' => ['label' => 'Loan amount', 'type' => 'number'],
        'approval_limit' => ['label' => 'Approval limit', 'type' => 'number'],
        'special_approval' => ['label' => 'Special approval', 'type' => 'boolean'],
        'zone_type' => [
            'label' => 'Zone', 'type' => 'string', 'options' => ['Green', 'Yellow', 'Red'],
        ],
        'customer.country' => ['label' => 'Country', 'type' => 'string'],
        'product_id' => ['label' => 'Product', 'type' => 'integer'],
    ],
],
```

Define the management ability in an application provider's `boot()` method:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('manage-rulecraft', function ($user): bool {
    return (bool) $user->is_admin; // Replace with your application's access check.
});
```

The example assumes your application provides an `is_admin` attribute. You can use your own policy or permission check instead. Spatie is not required for the builder. This management ability authorizes access to all builder operations; it is separate from business permissions such as Loan Approval.

The component and every load/validate/preview/save/asset endpoint enforce the configured ability. Default routes also use `web` and `auth`, including session authentication and CSRF protection. The builder remains inaccessible while disabled. Request-forgery protection follows the host Laravel version, including its origin checks where supported; see [Laravel CSRF protection](https://laravel.com/docs/13.x/csrf). Rebuild config/route caches after changing their corresponding settings.

## Embed the component

In your existing page:

```blade
@extends('layouts.app')

@section('content')
    <h1>Loan approval policies</h1>

    <x-rulecraft::builder group="loan-approval" />
@endsection
```

The selected group must exist and be a root. To start a new policy, omit `group`:

```blade
<x-rulecraft::builder />
```

Dynamic group selection works with a name string:

```blade
<x-rulecraft::builder :group="$groupName" />
```

The application supplies the page and layout. Multiple components on one page keep independent drafts and preview context, while shared styles/scripts load once. Bundled assets are served from authorized package routes with content-based version URLs. No asset publishing command is required.

## Build the loan approval example

Create `same branch AND (within limit OR special approval)`:

1. Enter root name `loan-approval` and choose ALL.
2. Click **Add condition**. In its three rows choose **Source: Field → Loan branch**, **Operator: Equals (==)**, and **Compare with: Field → User branch**. Use the visible **Rule name** input to name it `same-branch`.
3. Add child group `limit-or-special` and choose ANY.
4. In that child, add a condition: **Source: Field → Loan amount**, **Operator: Less than or equal (<=)**, **Compare with: Field → Approval limit**. Name it `within-limit` in **Rule details**.
5. Add a condition: **Source: Field → Special approval**, **Operator: Equals (==)**, **Compare with: Value → True**. Optionally name it `special`.
6. Enter this preview context:

```json
{
    "loan_branch_id": 1,
    "user_branch_id": 1,
    "loan_amount": 300000,
    "approval_limit": 200000,
    "special_approval": true
}
```

**Preview conditions** shows PASS for the root, same branch, the ANY child, and special approval; within-limit shows FAIL. Changing `loan_branch_id` to `2` fails the root. Preview leaves saved records untouched. **Validate** checks authoring structure and syntax; it does not require sample data or a passing decision. **Save policy** stores the complete tree.

A root can contain only child groups. Empty groups remain valid structures that evaluate false. Enabled checkboxes follow the same ALL/ANY behavior as ordinary Rulecraft evaluations.

Every visual condition has three full-width rows: **Source**, **Operator**, and **Compare with**. Operator labels include their expression symbols, such as **Equals (==)**. Source/target kinds and their inputs sit together; a read-only typed signature appears beneath each function selector.

Each condition heading displays its rule name and updates as you edit it. An empty name temporarily shows Unnamed rule; saving still requires a valid name. New conditions receive a unique default name within their group (`rule-1`, `rule-2`, and so on). **Rule details** is always visible and contains the name, priority, and editor mode. On desktop name, priority, and editor share a row; on mobile the name sits above priority/editor. Field type appears beside Source, and numeric literal type beside Compare with. Known catalog field types appear as read-only badges; functions show their types in the signature. The optional **Messages** panel contains failure/success text; **Action** contains the handler name and parameters; **Generated expression** is a separate read-only panel. Each disclosure panel preserves its own state while editing controls that redraw the condition. Open **Messages**, below the condition editor, to edit the optional Failure and Success message fields. It starts collapsed, like Action, and its summary shows Defaults or the number of customized messages. Preview shows the actual rule outcome text and the combined group message. Clearing failure text restores its default; clearing success text makes passing rules silent. See [message behavior](rules.md#rule-messages). Higher priority runs first within a group; equal priorities use saved rule IDs. Registered action names appear as suggestions without constructing handlers. Existing/unregistered action names can be preserved; application execution still requires a valid registration. Parameters accept a JSON object, array, or `null`.

## Free-input fields

All visual rules use one `{source, operator, operand}` definition, without a version flag. Context-field sources store the field path and type; function sources store the function, arguments, and return type.

Leave `builder.fields` empty to enter context paths directly. For `loan_amount <= approval_limit`, enter `loan_amount` in **Field**, select the numeric **Field type** beside the source field, choose **Less than or equal (<=)**, set **Compare with** to **Field**, and enter `approval_limit`. Supply those keys in the preview/evaluation context. Dot paths such as `loan.amount` refer to nested arrays.

No field registration is required. A field type guides authoring and literal validation; it does not cast or prove the type of runtime context. A mistyped or missing key produces an evaluation error in preview. Optional configured fields continue to provide friendly labels, dropdowns, compatible field suggestions, and allowed values.

## Custom expression providers

A standard Symfony provider already works in expression mode. To offer its functions as visual controls, implement the optional `Thuraaung\Rulecraft\Contracts\BuilderExpressionProvider` interface. It extends Symfony's provider interface and adds `getBuilderFunctions()`.

This complete provider looks up a credit score from the supplied context. Applications may replace the lookup with their domain logic; keep evaluation functions pure when previews must avoid side effects.

```php
<?php

namespace App\Rules;

use InvalidArgumentException;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Thuraaung\Rulecraft\Contracts\BuilderExpressionProvider;

class CreditFunctions implements BuilderExpressionProvider
{
    /** @return list<ExpressionFunction> */
    public function getFunctions(): array
    {
        return [new ExpressionFunction(
            'credit_score',
            function (string $applicantId): string {
                return '$variables["credit_scores"]['.$applicantId.']';
            },
            function (array $context, int $applicantId): int {
                $score = $context['credit_scores'][$applicantId] ?? null;
                if (! is_int($score)) {
                    throw new InvalidArgumentException('Provide an integer credit score.');
                }

                return $score;
            }
        )];
    }

    /** @return array<string, array<string, mixed>> */
    public function getBuilderFunctions(): array
    {
        return [
            'credit_score' => [
                'label' => 'Credit score',
                'arguments' => [
                    'applicant_id' => ['label' => 'Applicant ID', 'type' => 'integer'],
                ],
                'return_type' => 'integer',
            ],
        ];
    }
}
```

Register the provider once in `config/rulecraft.php`:

```php
'expression_providers' => [App\Rules\CreditFunctions::class],
```

In a visual rule, choose **Source → Function**, then **Function → Credit score**. The builder shows this signature beneath the selector:

```text
credit_score(int applicant_id): int
```

The argument binds to `applicant_id` in the evaluation context automatically. There are no function type, argument-source, or argument-value inputs. Choose **Greater than or equal (>=)** and enter value `650` in the comparison row. Validation generates `credit_score(applicant_id) >= 650` without calling the evaluator. Preview with:

```json
{
    "applicant_id": 1,
    "credit_scores": {"1": 700}
}
```

The rule passes. A score of `600` denies it; a missing `applicant_id` records an expression error, and an absent score records the provider's error. Save/reload restores the function and signature in both themes. Function metadata is kept in `builder_definition`; normal runtime evaluation still uses `expression` and the registered provider. Type annotations are display text only; the expression contains no `int` annotations or automatic casts.

The signature displays metadata types as `int`, `int|float`, `bool`, and `string`. Zero-argument functions display, for example, `current_limit(): int|float`. Multiple arguments appear in declared order, such as `adjust_score(int score, int|float bonus): int|float`, and bind to the `score` and `bonus` context keys.

Use expression mode for a custom call such as `credit_score(guarantor_id)` or `credit_score(1)`. Definitions supplied through the builder API may also contain field mappings or literal arguments. Loading those definitions preserves their actual bindings and displays them read-only; choosing a different function creates its default matching-key bindings.

Metadata rules:

- Each exposed function must exist in that provider's `getFunctions()`. Unlisted functions remain expression-only; duplicate registered function names are configuration errors in the builder.
- `label`, `arguments`, and `return_type` are required. Argument entries have a label and type. Scalar types are `string`, `integer`, `number`, and `boolean`.
- Arguments are required and ordered exactly as the function's callable parameters, excluding the evaluator's first context parameter. Use `arguments => []` for a zero-argument function. New visual selections bind each argument to the context key with the same metadata name. Nested function calls, optional/variadic argument forms, and array-valued arguments use expression mode.
- The declared return type selects valid comparison operators and literal types. Functions can be compared with a value, a context field, another compatible custom function, null where allowed, or a typed membership list. Numeric returns support ordered comparisons; boolean/string returns support equality and membership.
- Metadata describes the callable; developers must keep it consistent with the PHP implementation. Actual context values and function return values are not cast by the builder.
- The server checks the provider, argument names/order/types, paths, and return type before generating the expression. Client-generated expression text is replaced. Preview executes the provider; validation and saving do not execute evaluation callbacks.

### Compare with a custom function

Choose **Function** under **Compare with** to put a function on the right-hand side. The **Compared function** dropdown offers compatible return types and shows a read-only typed signature with automatic context binding, just like Source. Integer and number return types are compatible. Changing a scalar comparison operator preserves the selected comparison function and its arguments.

Using the credit-score provider above:

1. Set **Source → Field**, enter `minimum_score`, and choose integer Field type.
2. Select **Less than or equal (<=)** and **Compare with → Function**.
3. Select **Compared function → Credit score**. Its signature is `credit_score(int applicant_id): int`.
4. Preview this context:

```json
{
    "applicant_id": 1,
    "minimum_score": 650,
    "credit_scores": {"1": 700}
}
```

The generated expression is `minimum_score <= credit_score(applicant_id)`. It passes; changing the applicant's score to `600` fails. `===` also compares the actual returned PHP types, without casting them.

For function-to-function comparisons, register both functions and expose their metadata. For example, if your provider also declares `required_score(): int` with `arguments => []`, choose **Credit score** as Source, **Greater than or equal (>=)**, and **Required score** as Compared function. This generates `credit_score(applicant_id) >= required_score()`. The applicant's score must meet the value returned by your `required_score` implementation. Each function binds its own declared context keys automatically.

Saving, reloading, and policy transfer preserve both functions and their typed arguments. Server validation checks the function on each side without executing evaluation callbacks. Removing or changing either function's builder metadata falls back to the complete raw expression. `in`/`not in` still require literal lists because visual functions declare scalar return types. Nested function arguments remain available in expression mode.

Removing the provider, removing its visual metadata, or changing its signature opens saved rules in expression mode without rewriting the stored expression. Executing an expression still requires its provider. Policy JSON version 2 preserves these definitions; destination applications must register the function implementation, but need no visual metadata to import/evaluate the expression. No PHP handler/provider code is transferred.

## Expression mode

Existing rules without reliable visual metadata open in expression mode. Their exact expression text is preserved, including whitespace and custom functions. Unavailable function metadata, changed function signatures, or stale definitions cause a code fallback; loading alone does not change stored rules.

The **Editor** control is always visible in **Rule details**. Switching a visual rule to **Expression** asks the server to generate its expression first. Switching a code rule to **Visual** explicitly replaces that code with a new visual condition after confirmation; there is no automatic reverse parser. You can cancel and retain the original code.

Visual conditions use configured or freely entered fields and type-appropriate operators. Strings/booleans support equality and membership; numeric fields also support ordered comparisons. Field-to-field comparisons require compatible types. Dot paths such as `customer.country` generate array access such as `customer["country"]`.

For a numeric field, **Number type** distinguishes integer and decimal literals, including under `===`. Numeric literals use typed string representations in editor metadata, preserving large integers and decimal semantics through browser JSON. Runtime context is never cast by the builder. Numeric membership lists accept JSON such as `[2, 5, 8]`; string lists use `["Green", "Yellow"]`.

Ordinary model saves clear visual metadata if the expression no longer matches. Bulk database updates bypass model events, but loading/export detects stale metadata and falls back to the exact expression. See the [expression reference](../expressions.md) for syntax.

## Styles and CSS overrides

Choose `theme => 'custom'` for the neutral custom preset or `theme => 'bootstrap'` for the bundled, scoped Bootstrap 5.3.8 preset. The custom preset is shadcn-inspired Blade/CSS; it uses no official React components. Only the selected preset loads. Bootstrap's selectors are scoped to the builder so its reset does not restyle the host page.

Create `public/css/rulecraft-overrides.css` and set `builder.stylesheet` to `css/rulecraft-overrides.css`. Local absolute asset paths also work; external/scheme URLs are rejected. Your stylesheet loads after bundled styles.

```css
.rulecraft-builder {
    --rc-primary: #2563eb;
    --rc-on-primary: #fff;
    --rc-radius: 8px;
    --rc-font-family: Arial, sans-serif;
}

.rulecraft-builder .rc-rule {
    padding: 16px;
    margin-bottom: 12px;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
}
```

| Variable | Purpose |
| --- | --- |
| `--rc-primary` / `--rc-on-primary` | Primary button/accent and its foreground. |
| `--rc-surface` / `--rc-background` | Surface and inset backgrounds. |
| `--rc-text` / `--rc-muted` | Main and secondary text. |
| `--rc-border` | Borders and dividers. |
| `--rc-radius` | Panel radius; controls use a slightly smaller radius. |
| `--rc-spacing` | Group and rule padding/gaps. |
| `--rc-font-family` | Builder typography. |

Customization selectors include `.rc-group`, `.rc-rule`, `.rc-field`, `.rc-label`, `.rc-input`, `.rc-button`, `.rc-button-primary`, `.rc-preview`, `.rc-results`, and `.rc-message`. Scope overrides under `.rulecraft-builder`. Theme variables and these selectors apply to both presets.

To override the component markup:

```bash
php artisan vendor:publish --tag=rulecraft-builder-views
```

Edit `resources/views/vendor/rulecraft/components/builder.blade.php`. Preserve `data-rulecraft-builder`, the serialized `data-settings`, and a unique root ID for the JavaScript editor. Attributes passed to the component are merged into its root element.

## Saving and conflict handling

Saving validates the entire draft and uses one transaction. Existing IDs and permission mappings remain intact. Removed rules/child groups are deleted only on save. Existing nodes stay under their original parent; moving/reordering existing groups by ID is not supported in this release. Use rule priorities for execution order.

The editor sends a revision obtained when loading/saving. If the selected tree changed since that snapshot, the server returns `409` and keeps the unsaved draft on screen. Reload and reconcile changes before retrying. Duplicate group names and invalid structures fail without partial writes. A database error or canceled model save also rolls back the edit.

Standard Eloquent events still fire on persistence. Their external side effects are application-owned and cannot be undone by database rollback. The builder does not call `run()` when saving.

## Preview and JSON transfer

Preview creates transient models in memory and uses the shared group evaluator. It respects enabled states, context schemas, and hierarchy limits. It emits no normal stored-policy evaluation events and performs no policy writes or action-handler resolution. Actual expression evaluation can invoke custom functions/object methods supplied by your application; syntax validation does not invoke them.

Context and action parameters are sent with their JSON text where needed so browser serialization does not collapse `1.0` into `1` or turn `{}` into `[]`. Keep sample context in the editor; persistent drafts and revision history are not included.

The existing PHP/CLI import/export APIs also work with builder rules. Export uses version 2 when a valid visual definition is present anywhere in the tree; every rule then includes nullable `builder_definition`. Expression-only trees retain version 1. Both versions import, and imports still reject existing group names. Field catalogs, handlers, provider code, and permission mappings are configured in the destination application. Context-field definitions include their types and reopen visually without a destination catalog. Function editing still requires matching provider metadata.

See [transfer examples](policy-management.md#import-and-export-policies) and the [builder API reference](../api-reference.md#builder).
