# Policy management examples

[Documentation home](../../README.md) · [All examples](../examples.md)

Test a saved policy, validate its input, suspend conditions, or move its tree between environments. Each section includes its commands or code, expected results, and relevant failure behavior. Start with [Getting started](../../README.md#getting-started) if Rulecraft is not installed yet.

- [Example policy](#example-policy)
- [Test a saved policy from the terminal](#test-a-saved-policy-from-the-terminal)
- [Validate context](#validate-context)
- [Enable or disable policies](#enable-or-disable-policies)
- [Import and export policies](#import-and-export-policies)

## Example policy

The examples use `loan-approval`: same branch AND (within limit OR special approval). If that group already exists from the nested loan example, reuse it. Otherwise, create this sample once in a seeder. Group names are globally unique.

<details>
<summary>Create the example policy and context</summary>

```php
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RuleGroup;

$root = RuleGroup::create(['name' => 'loan-approval', 'match' => 'all']);
$root->rules()->create([
    'name' => 'same-branch',
    'expression' => 'loan_branch_id == user_branch_id',
]);

$alternatives = $root->groups()->create([
    'name' => 'limit-or-special',
    'match' => 'any',
]);
$alternatives->rules()->createMany([
    ['name' => 'limit', 'expression' => 'loan_amount <= approval_limit'],
    ['name' => 'special', 'expression' => 'special_approval == true'],
]);

$data = [
    'loan_branch_id' => 1,
    'user_branch_id' => 1,
    'loan_amount' => 300000,
    'approval_limit' => 200000,
    'special_approval' => true,
];
```

</details>

The sections below can be used independently. When a PHP example needs `$data`, use the context from the setup above. Re-enable any groups/rules you disabled before testing a different behavior.

## Test a saved policy from the terminal

With the example policy saved and enabled, pass its context directly as a quoted JSON string:

```bash
php artisan rulecraft:test loan-approval --data='{
    "loan_branch_id": 1,
    "user_branch_id": 1,
    "loan_amount": 300000,
    "approval_limit": 200000,
    "special_approval": true
}'
```

Expected output:

```text
PASS  loan-approval [ALL]
  PASS  same-branch
  PASS  limit-or-special [ANY]
    FAIL  limit: Rule "limit" did not pass.
    PASS  special
```

The command labels disabled nodes `DISABLED` and omits their unvisited descendants. It uses `evaluate()` only: it never resolves action handlers or calls `run()`. Every evaluated branch is displayed, even when another branch already determines the result. Ordinary failed rule lines include their custom or default failure message; passing rules include success text when configured. Rule lines with expression errors display `ERROR` followed by the error message; containing groups also display `ERROR`.

| Exit code | Meaning |
| --- | --- |
| `0` | The selected group passed with no expression errors. |
| `1` | Valid evaluation, but conditions did not pass (including an empty group). |
| `2` | Missing/empty context string, malformed JSON, non-object JSON root, expression error, missing group, invalid hierarchy, or another evaluation failure. |

`--data` is required and accepts JSON directly. Use a JSON object; `--data='{}'` is valid for policies requiring no variables. Arrays and scalar roots are rejected. In Bash/zsh, wrap JSON in single quotes to preserve its double quotes, whitespace, and special characters. File paths are not read. Nested objects become arrays for expression access such as `customer["country"]`. The command does not store or modify the supplied data.

The command applies any configured context rules before evaluating each group. Invalid context prints field messages such as `ERROR  context.loan_amount: The loan amount field is required.` and returns `2`. Without a configured schema, missing expression variables remain expression errors. The command emits normal evaluation events, so registered listeners may perform work. Custom functions/object methods can also perform work even though action handlers never run. Use pure extensions and appropriate application listeners when testing must avoid side effects.

## Validate context

Context validation is optional. Define Laravel validation rules in `config/rulecraft.php`, keyed by the exact saved group name:

```php
'context_rules' => [
    'loan-approval' => [
        'loan_branch_id' => ['required', 'integer'],
        'user_branch_id' => ['required', 'integer'],
        'loan_amount' => ['required', 'numeric', 'min:0'],
        'approval_limit' => ['required', 'numeric', 'min:0'],
        'special_approval' => ['required', 'boolean'],
        'customer.country' => ['sometimes', 'string'],
        'items.*.quantity' => ['sometimes', 'integer', 'min:1'],
    ],
],
```

No schema, or an empty schema, leaves existing evaluation behavior unchanged. Dot notation and `*` apply to nested input using Laravel's validation rules. The group-name key is literal: `loans.approval` identifies that exact group, not a nested configuration path. Rebuild Laravel's config cache after changing these rules.

Use the enabled `loan-approval` example group. Its required context must satisfy validation before its expressions run:

```php
$result = Rulecraft::group('loan-approval')->evaluate([
    'loan_branch_id' => 1,
    'user_branch_id' => 1,
    'loan_amount' => 'invalid',
    'approval_limit' => 200000,
    'special_approval' => false,
]);

$result->passed(); // false
$result->hasErrors(); // true
$result->validationErrors();
// ['loan_amount' => ['The loan amount must be a number.']]
// Message wording follows your Laravel version, locale, and translations.
$result->rules()->count(); // 0: this group's expressions were not evaluated
$result->groups()->count(); // 0: its descendants were not evaluated
```

`validationErrors()` returns this group's field-message map, or `[]` when it has no validation errors. `errors()` includes an `Illuminate\Validation\ValidationException`; its `errors()` method exposes the same field messages. Validation failures are returned in the result, not thrown as request-validation responses.

Each visited group applies its own configured schema before its own expressions and descendants. When a child fails validation, inspect `$result->groups()->get('child-name')->validationErrors()`. The parent's `errors()` aggregates that child's exception, and the parent fails even when another `any` branch passes. Parent expressions may already have run before a child is visited. A directly selected child applies its own schema without inheriting its parent's schema. Use a root schema for input required by the whole policy.

Validation checks values without casting, adding defaults, or removing extra fields. Laravel's `integer`, `numeric`, and `boolean` rules accept some non-native representations (for example, `integer` accepts a numeric string); use a custom Laravel validation rule if strict native PHP types are required. For `amount === 100`, supplying `"100"` still fails the expression even if `integer` validation passed.

Use `sometimes` for optional fields and `nullable` to allow explicit nulls. Neither supplies a value for an expression: normalize optional input when the expression expects a variable/key. Keep configured rules compatible with every Laravel version you support. String/array rule definitions work with config caching; closures or rule objects need application-specific care and should not be assumed cacheable.

The same validation applies through `evaluate()`, `run()`, Blade conditions, conditional permissions, and `rulecraft:test`. A failing selected group runs no actions; its failed `ExecutionResult` retains the original context. Permission results collect validation errors by mapped group, while another independently valid grant may still allow access. Invalid schema configuration or unknown validation rules remain configuration exceptions rather than ordinary input failures.

## Enable or disable policies

Groups and rules have an `enabled` boolean attribute, defaulting to `true`. The single initial migration creates these columns along with the rest of the package schema, using your configured group and rule table names.

To suspend the loan policy defined above:

```php
$group = RuleGroup::where('name', 'loan-approval')->firstOrFail();
$group->update(['enabled' => false]);

$result = Rulecraft::group('loan-approval')->evaluate([]);
$result->passed(); // false
$result->disabled(); // true
$result->hasErrors(); // false
$result->validationErrors(); // []
$result->rules()->count(); // 0
$result->groups()->count(); // 0
```

A disabled group skips context validation, its expressions, and its descendants. `run()` returns a failed execution with no attempted actions. Re-enable it with `$group->update(['enabled' => true])`; its rules and permission mappings remain intact. You can also set `enabled` when creating groups/rules. Use boolean `true` or `false` for this attribute.

To disable one condition after re-enabling the group:

```php
$group->update(['enabled' => true]);
$rule = $group->rules()->where('name', 'same-branch')->firstOrFail();
$rule->update(['enabled' => false]);

// Use the complete $data context from the example policy setup above.
$result = Rulecraft::group('loan-approval')->evaluate($data);
$result->passed(); // false: this ALL group still requires same-branch
$result->disabled(); // false: the group itself is enabled
$result->rules()->first()->disabled(); // true
$result->rules()->first()->error(); // null

$rule->update(['enabled' => true]);
```

Disabled nodes count as non-passing; they are not removed from the conditions. This ensures that disabling a required ALL condition cannot weaken a policy.

| Situation | Decision |
| --- | --- |
| Selected group is disabled | Denied, `disabled() === true`; no rules or descendants visited. |
| A selected child's ancestor is disabled | Denied, `disabled() === true`, even if that child is enabled. |
| ALL group contains a disabled rule or child group | Denied. |
| ANY group contains a disabled rule or child group | Passes only if another enabled branch passes and no evaluated errors occur. |
| Group has no enabled passing conditions, or is empty | Denied for both ALL and ANY. |

Only enabled, visited nodes contribute expression/context errors. Disabling a broken ANY alternative can let a different enabled alternative pass. Keep mandatory guards in an ALL group.

A disabled rule does not evaluate its expression, produce an expression error, or resolve/run its action. Its `RuleResult` remains in the visited rule collections with `failed() === true` and `disabled() === true`. Disabled child groups appear in `groups()` with empty rule/child collections. Their descendants are omitted. `stopOnFailure()` treats a disabled condition as a failure in an ALL selection.

Selecting a child directly still ignores its parents' expressions, but honors every ancestor's enabled state. Disabling a root therefore suspends direct checks of its descendants too. Enabled-state checks query current database records on every evaluation. Previously returned results retain their decision/disabled snapshots even if the associated models are later updated.

Blade and permission checks use the same state rules. A disabled permission-mapped group denies that grant without an error; a different independently passing grant can still authorize. The CLI prints `DISABLED` for disabled group/rule results. A disabled selection returns exit code `1`; an enabled ANY selection can return `0` while displaying a disabled alternative.

States are part of the initial schema. Rolling back its migration removes all package tables and their records, including saved states. Running the migration again creates empty tables; newly created groups/rules default to enabled.

## Import and export policies

Move a complete policy between environments as a JSON string or file. Export a root group, such as `loan-approval`; its direct rules and all descendants travel together. Import creates a new tree and rejects any group name already present in the database.

### JSON strings and clipboard

Export prints only JSON to standard output, so you can copy it directly on macOS:

```bash
php artisan rulecraft:export loan-approval | pbcopy
```

To inspect or copy the JSON manually, omit the pipe. To import a copied policy on macOS:

```bash
php artisan rulecraft:import --json="$(pbpaste)"
```

You can also pass a complete JSON document directly in single quotes with `--json`; the full format appears below. Import validates expressions without evaluating them and never resolves or runs action handlers. Configure custom expression functions in the destination application before importing policies that use them.

### JSON files

Choose a location appropriate to your application, for example a `policies/` directory in the application root. There is no required storage folder. Create the parent directory first:

```bash
mkdir -p policies
php artisan rulecraft:export loan-approval --output=policies/loan-approval.json
php artisan rulecraft:import --file=policies/loan-approval.json
```

The import command above is for a destination where those group names do not already exist. Relative paths resolve from the terminal's current working directory; absolute paths also work. Quote paths containing spaces. `--output` creates a new file and refuses existing paths and symbolic links. It does not create directories or overwrite files. Imports require exactly one of `--file` or `--json`, with a nonempty value.

Both commands return exit code `0` on success and `2` on failure. Errors go to standard error. Export without `--output` emits JSON only, with a trailing newline; file export and import print a success message. A shell pipeline normally reports its last command's status; use `set -o pipefail` in Bash/zsh if your script needs to detect an export failure before `pbcopy`.

### PHP API

```php
use Thuraaung\Rulecraft\Facades\Rulecraft;

$json = Rulecraft::export('loan-approval'); // string: complete versioned JSON

// In a destination where the exported group names do not exist:
$root = Rulecraft::import($json); // saved Models\RuleGroup, with parent_id === null
$result = Rulecraft::group($root->name)->evaluate($data);
$result->passed(); // Decision for the supplied context, not an import success flag.
```

`export()` returns pretty-printed JSON without a trailing newline; `import()` returns the saved root model. Children and rules are available through its Eloquent relationships. Invalid JSON, schema, expression syntax, excessive depth, or duplicate names throw `InvalidPolicyException`. A missing export group throws `RuleGroupNotFoundException`; invalid stored hierarchy throws `InvalidGroupHierarchyException`. Database and model-event exceptions propagate. See the [API reference](../api-reference.md#policy-importexport) for return and error details.

Exports automatically use version 2 when any valid visual definition is present. Every rule then has a `builder_definition` object or `null`; group fields stay the same. Expression-only exports retain the version 1 schema below. Imports accept both versions and verify definitions match expressions without requiring a destination field catalog. See [builder transfer details](builder.md#preview-and-json-transfer).

### Version 1 format

This complete example describes a single within-limit policy:

```json
{
    "version": 1,
    "group": {
        "name": "loan-approval",
        "match": "all",
        "enabled": true,
        "rules": [
            {
                "name": "within-limit",
                "expression": "loan_amount <= approval_limit",
                "failure_message": "The loan amount exceeds your approval limit.",
                "success_message": "The loan amount is within your approval limit.",
                "enabled": true,
                "priority": 0,
                "action": null,
                "action_parameters": null
            }
        ],
        "groups": []
    }
}
```

Add child objects with the same group fields to `groups`; every child can have its own `rules` and `groups`. Empty arrays are valid, and groups containing only child groups are supported. Empty groups retain the usual failing evaluation behavior.

| Field | Accepted value |
| --- | --- |
| `version` | Integer `1` for expression-only exports or `2` for visual metadata. Both import. |
| Group/rule `name` | Nonblank string, at most 191 characters. Group names must be unique throughout the document and destination database. |
| Group `match` | `all` or `any`. |
| Group/rule `enabled` | JSON boolean `true` or `false`. |
| `rules` / `groups` | JSON arrays, including `[]`; each element must follow its complete schema. |
| Rule `expression` | Nonblank Symfony expression with valid syntax and registered functions. Runtime variable names are accepted without context data. |
| Rule `success_message` | Optional string or `null`; omission means no success text. Preserved in both formats. |
| Rule `failure_message` | Optional string or `null`; omission defaults to null. Export preserves it in both formats. |
| Rule `priority` | Signed 32-bit JSON integer; higher values evaluate first. Equal priorities retain their exported relative order. |
| Rule `action` | `null` or an action name of at most 191 characters. Names do not need a registered handler during transfer. |
| Rule `action_parameters` | `null`, a JSON object, or a JSON array; nested objects/lists are preserved. |

All listed fields except `failure_message` and `success_message` are required, including `enabled`, `action`, and `action_parameters`. Unknown fields are rejected. Group nesting obeys `rulecraft.max_depth`. Every expression is checked, including disabled rules. Export applies these checks to stored policies too, so malformed stored expressions cannot produce an accepted export.

Import validates the entire document before inserting anything and saves the tree in one database transaction. Any duplicate group name, constraint error, or canceled model save fails the import; existing policies remain unchanged. There is no overwrite or merge mode. Standard Eloquent model events still fire; listeners should defer external side effects until commit because a database rollback cannot undo them.

IDs, parent IDs, timestamps, role/permission assignments, context validation schemas, handler classes, and provider code are application-managed and do not travel in the document. Import assigns new IDs and derives parent relationships from nesting. Register the intended actions and configure validation/permission mappings in the destination before using an imported policy. JSON carries action names and parameters only.

For expression syntax, see the [expression reference](../expressions.md). For nested group design, see the [rule examples](rules.md). The [API reference](../api-reference.md) lists every method and exception.
