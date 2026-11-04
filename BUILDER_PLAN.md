# Rulecraft Builder plan

Status: Implemented locally on 2026-11-01, including free fields and visual expression providers. PHP 7.3/Laravel 7 and 8, plus PHP 8.0/Laravel 9, verified locally on 2026-11-02; remote CI remains unverified.
Created: 2026-10-31
Related task: [TODO item 6](TODO.md#6-rulecraft-builder)

## Goal and agreed direction

Provide an optional visual rule editor that developers can embed in their own Laravel pages. Use the existing Rulecraft engine and models, keeping Laravel conventions, SOLID, SLAP, and clean code practical. Add only the abstractions needed for editing, compilation, preview, and saving.

Agreed UI direction:

- Blade-based UI with plain JavaScript and CSS.
- No React, Inertia, or required Tailwind dependency.
- Two predefined style presets: Bootstrap and a custom preset with a clean, shadcn-inspired appearance.
- Developers can customize CSS variables, load their own stylesheet, and publish/override Blade views.
- A reusable Blade component fits into the consuming application's layout.
- Preserve PHP 7.3-compatible source and Laravel 7–13 support on compatible runtimes.

The first release follows the component/configuration examples below. The current [builder guide](docs/examples/builder.md), [configuration](docs/configuration.md#builder), and [API reference](docs/api-reference.md#builder) describe the implemented contracts.

## First release scope

| Area | Planned behavior |
| --- | --- |
| Policy editing | Create a root policy or load an existing root and edit its complete tree. |
| Groups | Add/remove child groups and select ALL/ANY; allow rules, children, or both. |
| Rules | Add/edit/remove rules using fields, operators, and typed operands. |
| Field comparisons | Compare a field against a literal or another compatible field. |
| Policy attributes | Edit names, enabled states, priorities, action names, and JSON parameters. |
| Expression mode | Preserve and edit expressions that cannot be represented visually. |
| Validation | Validate structure, field/operator choices, expressions, and hierarchy limits. |
| Preview | Evaluate an unsaved draft with sample JSON and show nested results/errors. |
| Saving | Persist the complete edit transactionally while retaining existing identities. |
| Styling | Select Bootstrap or custom CSS, with documented customization points. |
| Integration | Embed the component in an application page with protected backend endpoints. |

Do not build a separate administration application, authentication system, or permission editor. Existing role-permission assignments remain managed by the application. Removing rules/child groups in a draft takes effect only when the edit is saved.

## Component API

Edit an existing root policy inside the consuming application's page:

```blade
@extends('layouts.app')

@section('content')
    <h1>Loan approval rules</h1>

    <x-rulecraft::builder group="loan-approval" />
@endsection
```

Create a new policy:

```blade
<x-rulecraft::builder />
```

Choose the group name dynamically:

```blade
<x-rulecraft::builder :group="$groupName" />
```

The component owns the editor controls, draft state, validation/preview display, and save interaction. The application owns the surrounding page and layout. The first release edits complete root trees; existing engine APIs still support direct child evaluation.

Load shared assets once per page. Keep editor instances independent if more than one component is rendered. Rendering the component must not grant access to its backend endpoints.

## Styling and CSS customization

Configuration under `config/rulecraft.php`:

```php
'builder' => [
    'enabled' => false,
    'theme' => 'custom', // custom or bootstrap
    'stylesheet' => 'css/rulecraft-overrides.css',
],
```

`custom` is the default. `stylesheet` is optional and refers to a public application asset. It resolves through Laravel asset URLs; external URL schemes are rejected.

Preset requirements:

- Bootstrap: verify the latest stable release against its official documentation when implementing the preset, pin the selected version, and document it.
- Custom: implement the appearance with Blade and CSS. This is a shadcn-inspired design, not the official React component implementation.
- Both presets share editor behavior, backend validation, and expression generation.
- Load only the selected preset and load application overrides afterward.
- Ship ready-to-use assets so consuming applications do not need a frontend build step.
- Scope package-specific styles under `.rulecraft-builder` and use stable `rc-` class names.
- Bundled Bootstrap 5.3.8 selectors are scoped to `.rulecraft-builder:where(.rc-theme-bootstrap)` so embedding does not reset the host layout.
- Blade views are publishable with `rulecraft-builder-views`, using Laravel view overrides under `resources/views/vendor/rulecraft`.

Example application file, `public/css/rulecraft-overrides.css`:

```css
.rulecraft-builder {
    --rc-primary: #2563eb;
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

Define and document the supported variable/class contract, including which settings apply to both presets. Include visible keyboard focus, labels, error messages, and responsive layouts in both presets.

## Field catalog and operators

Field configuration is optional. Without a catalog, context paths are entered freely with a type selector. Applications may configure friendly labels, dropdowns, and typed options. Do not infer the catalog from database tables: evaluation context is application data and may include calculated or user-specific values.

Illustrative configuration within `builder`:

```php
'fields' => [
    'loan_branch_id' => ['label' => 'Loan branch', 'type' => 'integer'],
    'user_branch_id' => ['label' => 'User branch', 'type' => 'integer'],
    'loan_amount' => ['label' => 'Loan amount', 'type' => 'number'],
    'approval_limit' => ['label' => 'Approval limit', 'type' => 'number'],
    'special_approval' => ['label' => 'Special approval', 'type' => 'boolean'],
    'zone_type' => [
        'label' => 'Zone',
        'type' => 'string',
        'options' => ['Green', 'Yellow', 'Red'],
    ],
],
```

Initial operators should cover equality/inequality, strict equality where selected, ordered comparisons, and `in`/`not in` typed lists. Offer only operators and field operands appropriate to the configured type. Make literal and field-reference operands visibly different.

Validate visual field references and operator choices server-side. Encode literals safely and test quotes, backslashes, Unicode, booleans, nulls, numbers, and arrays against the supported Symfony versions. Form input conversion must not introduce runtime context casting or change the engine's comparison semantics.

Optional field catalogs guide visual authoring. Custom providers can expose typed visual functions through `BuilderExpressionProvider::getBuilderFunctions()`; see the [implemented provider API](docs/examples/builder.md#custom-expression-providers). Raw expressions retain the engine's syntax and custom-function behavior; do not silently remove an existing variable because it is absent from the catalog.

## Visual and expression editing

Use the existing group tree for ALL/ANY structure. Store only the rule-specific visual definition as additional metadata, rather than duplicating the complete group hierarchy.

Implemented behavior:

- A visual rule has one typed definition and a server-generated Symfony expression.
- Store the definition and generated expression atomically.
- An expression-mode rule uses its exact expression text as the source.
- Existing rules without reliable visual metadata open in expression mode.
- Opening a rule never rewrites its expression or approximates unsupported syntax.
- Switching from visual to expression mode explicitly shows the generated expression.
- Saving a code edit invalidates visual metadata that no longer matches.
- Detect expression changes made through ordinary Eloquent/application code as well as the editor.
- Invalid expressions can remain in browser draft state but cannot be saved as valid builder rules.
- Syntax validation must not execute custom functions or action handlers.

Illustrative visual definition for a field-to-field comparison:

```json
{
    "source": {
        "type": "field",
        "field": "loan_amount",
        "value_type": "number"
    },
    "operator": "<=",
    "operand": {
        "type": "field",
        "field": "approval_limit"
    }
}
```

The generated expression is `loan_amount <= approval_limit`. A literal operand would carry a typed value instead. The editor also includes `value_type` for literals and uses numeric strings where needed to preserve their types through browser JSON.

Custom-function visual editors use optional provider metadata. Each rule also has optional plain-text `failure_message` and `success_message`, editable in a collapsed-by-default Messages panel below the condition editor, alongside the separate Action panel. Its summary shows Defaults or the number customized; its disclosure state is independent of other panels. Preview shows actual rule outcome text and combined group messages, keeping expression errors separate; save/load, revision checks, and policy transfer preserve the text. Show signatures such as `credit_score(int applicant_id): int` beneath selectors on either side, and bind arguments to matching context keys without mapping controls. Preserve actual bindings when loading definitions. Automatic expression-to-visual conversion remains deferred; preserve unsupported expressions faithfully in code mode.

## Validation and draft preview

Provide backend operations to load a root, validate a draft, preview a draft with JSON context, and create/update the tree. Keep endpoints thin and use focused collaborators for the substantive work.

Preview requirements:

- Reuse the existing expression and group semantics through the smallest necessary shared evaluation collaborator.
- Evaluate drafts without inserting temporary policy records or changing saved state.
- Never resolve or execute action handlers.
- Show direct/child pass/fail results, disabled states, field validation messages, and expression errors.
- Honor configured context schemas, enabled states, ALL/ANY behavior, and depth limits.
- Evaluate custom functions only when performing an actual preview; validation alone only parses.
- Do not treat preview as side-effect-free when application expression extensions perform work.
- Keep draft preview separate from normal stored-policy completion events. Draft previews emit no `GroupEvaluated` or `PermissionEvaluated` events for drafts.

Keep unsaved drafts in the page's editor state for the first release. Persistent drafts and revision history are later work.

## Persistence and JSON transfer

Reuse the current Eloquent models, hierarchy checks, and configured table names. Add the smallest nullable metadata storage needed on rules to the consolidated initial migration while the package remains unreleased; no separate builder database is planned. Schema changes after release would use migrations that preserve consumer data.

Save requirements:

- Validate the complete submission before writes and use a database transaction.
- Retain existing root, group, and rule IDs where those nodes remain.
- Preserve role-permission mappings; do not implement updates by deleting and re-importing a policy.
- Validate that submitted existing IDs belong to the selected tree.
- Preserve priorities, enabled flags, action names, parameter shapes, and unsupported expressions.
- Preserve existing action names even if they are not registered locally; registration is required when execution is requested, following current engine behavior.
- Expose registered handler names without constructing handlers. Do not accept PHP class definitions from the editor.
- Reject duplicate group names, cycles, excessive depth, and canceled/failed saves using established package behavior.
- Follow normal Eloquent event behavior; database rollback cannot undo external observer side effects.

Extend the versioned policy format so visual metadata survives export/import. Keep current version 1 imports supported as expression-based rules. Export automatically uses version 2 when valid visual metadata exists, otherwise version 1. Version 2 includes nullable `builder_definition` on every rule; version 1 keeps its existing strict schema. Application field catalogs, provider code, and handler registrations remain application-managed.

## Authorization and endpoint behavior

The builder is opt-in. Use the host application's authentication, web middleware, CSRF protection, and Laravel authorization for every load, validate, preview, and save operation.

Use the configured `manage-rulecraft` ability, denied unless Laravel authorizes it. This is separate from business permissions such as Loan Approval. Keep route prefix, middleware, and authorization integration configurable; the implemented keys are `route_prefix`, `middleware`, and `ability`.

Escape displayed names/expressions and render user-entered values as text. Enforce structural, field, and type validation on the server even when the browser already validates them. Use normal validation responses and preserve the editor's draft when a request fails.

## Implementation phases

### 1. Field configuration and expression generation

- [x] Finalize field definitions, type/operator rules, and typed visual metadata.
- [x] Implement server-side expression generation and syntax validation.
- [x] Define code-mode fallback and stale-metadata handling.
- [x] Add preservation, escaping, and semantic equivalence tests.

### 2. Draft validation, preview, and saving

- [x] Extract only the shared evaluation work required for unsaved drafts.
- [x] Implement draft validation and preview without database writes or actions.
- [x] Add metadata storage and transactional create/update behavior.
- [x] Define metadata-aware policy transfer with version 1 import support.
- [x] Add protected endpoints and test authorization, validation, and rollback.

### 3. Blade component and style presets

- [x] Implement the proposed component and nested group/rule controls.
- [x] Add field/value selection, expression mode, enabled states, and advanced action fields.
- [x] Add JSON preview input and nested result/error display.
- [x] Implement custom and Bootstrap presets, asset loading, and CSS overrides.
- [x] Add publishable views and verify independent instances and host-layout embedding.

### 4. Verification and documentation

- [x] Exercise browser behavior, keyboard access, error recovery, and both presets.
- [x] Run the package checks and supported local compatibility matrix.
- [ ] Verify unavailable runtime combinations through CI before claiming complete support.
- [x] Keep PHP source/examples within 100 columns and maintain property/generic PHPDoc.
- [x] Add a short builder workflow to README and complete configuration/API/examples pages.

Implementation of TODO item 6 was authorized on 2026-11-01. The checked phases below are complete locally. The previously unavailable PHP 7.3 and Laravel 9 runtime checks now pass in isolated containers; see [verification details](docs/development.md#minimum-runtime-verification). Remote CI remains unverified.

## Acceptance examples

1. Build and preview `same branch AND (within limit OR special approval)` with one comparison per rule.
2. Build the supplied zone/risk/region/product policy, including all specified product IDs.
3. Edit a root containing only child groups and preserve normal ALL/ANY results.
4. Open a custom-function or otherwise unsupported expression, save unrelated edits, and preserve its exact text and behavior.
5. Compare a field with another field and compare typed values/lists without changing expression semantics.
6. Save a valid edit with IDs/mappings intact; reject an invalid edit without partial writes.
7. Preview an unsaved edit without changing records, resolving handlers, or emitting normal stored-policy completion events.
8. Embed the component in an existing application page using either preset and an application stylesheet, with assets loaded once.
9. Reject unauthorized backend requests even when someone calls an endpoint directly.
10. Export/import a visual rule with metadata intact, and import existing version 1 documents through the expression fallback.

## Deferred work

- Automatic expression-to-visual conversion.
- Function selectors with read-only typed signatures and automatic context-key argument binding.
- Persistent drafts, revision history, and collaborative editing.
- Drag-and-drop ordering; priorities and explicit controls are sufficient initially.
- A separate React/Vue application, administration shell, or permission-management UI.

## Implementation references

- [Laravel package routes, views, assets, and publishing](https://laravel.com/docs/7.x/packages)
- [Laravel authorization](https://laravel.com/docs/7.x/authorization)
- [Bootstrap documentation](https://getbootstrap.com/docs/5.3/getting-started/introduction/)
- [Official shadcn Laravel integration](https://ui.shadcn.com/docs/installation/laravel) — background for the distinction between its React components and our custom Blade/CSS preset.

## Local verification (2026-11-01)

The initial builder adds 28 integration tests, including scoped/custom-table persistence, raw fallback, typed values, transactional saves, authorization, component rendering, configurable routes, and route serialization. Browser checks exercise both presets, custom CSS, nested editing, preview/save/reload, CSRF, stale edits, independent instances, keyboard focus, mobile layout, and escaping. See [PLAN.md](PLAN.md) for the final runtime matrix.
