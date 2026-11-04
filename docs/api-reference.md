# API reference

[Documentation home](../README.md) · [Configuration](configuration.md) · [Examples](examples.md)

Result classes live in `Thuraaung\Rulecraft\Results`; action classes live in `Thuraaung\Rulecraft\Actions`. Collections below are `Illuminate\Support\Collection` objects. Results are objects with accessor methods, not booleans or arrays. Use `->passed()` or `->successful()` explicitly; testing a result object itself with `if ($result)` does not test its decision.

- [Public entry points](#public-entry-points)
- [EvaluationResult](#evaluationresult)
- [RuleResult](#ruleresult)
- [ExecutionResult](#executionresult)
- [ActionOutcome and ActionResult](#actionoutcome-and-actionresult)
- [PermissionResult](#permissionresult)
- [Rule testing command](#rule-testing-command)
- [Policy import/export](#policy-importexport)
- [Blade conditions](#blade-conditions)
- [Evaluation events](#evaluation-events)
- [Builder](#builder)
- [Exceptions versus recorded failures](#exceptions-versus-recorded-failures)

## Public entry points

| Call                                                         | Returns                            | Behavior                                                                                   |
| ------------------------------------------------------------ | ---------------------------------- | ------------------------------------------------------------------------------------------ |
| `Rulecraft::group($name)`                                    | `GroupEvaluation`                  | Selects a named group; the database lookup occurs on evaluation.                           |
| `->stopOnFailure()`                                          | A cloned `GroupEvaluation`         | Enables early stopping for an `all` selection. Other pending evaluations remain unchanged. |
| `->evaluate(array $data)`                                    | `EvaluationResult`                 | Evaluates conditions without running actions.                                              |
| `->run(array $data)`                                         | `ExecutionResult`                  | Evaluates, then executes eligible actions if the selection passes.                         |
| `Rulecraft::for($user)`                                      | `Permissions\PermissionEvaluation` | Starts a conditional permission check.                                                     |
| `->permission($name)` / `->guard($name)`                     | A cloned `PermissionEvaluation`    | Selects a permission/guard without changing other pending checks.                          |
| Permission `->evaluate(array $data)`                         | `PermissionResult`                 | Evaluates mapped conditions for actual grants; never executes actions.                     |
| `Rulecraft::validateExpression($expression, $variableNames)` | `void`                             | Parses or throws; success has no return value.                                             |
| `Rulecraft::registerAction($name, $class)`                   | `void`                             | Registers a class or throws; it is not a fluent method.                                    |
| `Rulecraft::export($group)` | `string` | Exports a complete root policy as versioned JSON. |
| `Rulecraft::import($json)` | `Models\RuleGroup` | Creates a policy tree and returns its saved root; conflicts throw. |
| `RolePermissionRule::assign($role, $permission, $group)`     | `Models\RolePermissionRule`        | Inserts/updates the mapping for this saved role/permission pair.                           |

The facade is `Thuraaung\Rulecraft\Facades\Rulecraft`. For dependency injection, request `Thuraaung\Rulecraft\Rulecraft`; it exposes the same entry points.

## EvaluationResult

Returned by `Rulecraft::group($name)->evaluate($data)` and by `ExecutionResult::evaluation()`.

| Method               | Return type                            | Meaning                                                                                                                             |
| -------------------- | -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| `passed()`           | `bool`                                 | The selected group matched, with no expression or context-validation errors in its evaluated tree.                                  |
| `failed()`           | `bool`                                 | The inverse of `passed()`.                                                                                                          |
| `disabled()`         | `bool`                                 | This selected group or one of its ancestors was disabled at evaluation time.                                                        |
| `group()`            | `RuleGroup \| null`                    | The selected model. Package-generated results include it; manually constructed results can omit it.                                 |
| `rules()`            | `Collection<RuleResult>`               | Visited direct rule results in order, including disabled rules.                                                                     |
| `groups()`           | `Collection<string, EvaluationResult>` | Evaluated immediate children keyed by group name.                                                                                   |
| `allRules()`         | `Collection<RuleResult>`               | Direct rules and descendants, flattened in traversal order.                                                                         |
| `matchedRules()`     | `Collection<RuleResult>`               | All evaluated passing leaves, including leaves in failed branches.                                                                  |
| `failedRules()`      | `Collection<RuleResult>`               | All evaluated failing leaves, including those with errors.                                                                          |
| `failedMessage($separator = ' ')` | `string` | Ordinary failure messages from all visited direct/nested rules, joined with the separator; `''` when none. |
| `passedMessage($separator = ' ')` | `string` | Configured success messages from eligible passing branches; `''` if the selected group failed or no text exists. |
| `eligibleRules()`    | `Collection<RuleResult>`               | Passing leaves whose path to this result's selected group passed. Empty if this group failed. Includes leaves without action names. |
| `hasErrors()`        | `bool`                                 | An expression or context-validation error exists in this evaluated tree.                                                            |
| `errors()`           | `Collection<Throwable>`                | Expression and context-validation errors, including descendants, numerically indexed.                                               |
| `validationErrors()` | `array<string, array<int, string>>`    | Field messages for this group only; `[]` when this group has no validation failures.                                                |

A disabled group returns a denied result with `disabled() === true` and no visited rules/descendants. A disabled leaf remains in `failedRules()` with its own `disabled() === true` and no expression error. A parent containing a disabled node can fail while its own `disabled()` is false. States are snapshots; later model updates do not change an existing result.

A context-validation failure skips that group's rules and descendants, so `failedRules()` can be empty while `hasErrors()` is `true`. Inspect `validationErrors()` for its field messages. Child validation messages are on the child result; `errors()` aggregates their `Illuminate\Validation\ValidationException` instances.

`eligibleRules()` on the root governs its action execution. A child result describes its own subtree; calling that child's `eligibleRules()` does not add its parent's status. Normal evaluation visits every node; fail-fast results omit unvisited rules/groups rather than representing them as failures. Numeric result collections are indexed lists, not keyed by rule name or ID.

Example: for `same branch AND (within limit OR special approval)` with matching branches, amount 300000, limit 200000, and special approval `true`:

```php
$result->passed();                                    // true
$result->rules()->count();                            // 1: same-branch
$result->groups()->get('limit-or-special')->passed();  // true
$result->allRules()->count();                         // 3
$result->matchedRules()->count();                     // 2: same-branch, special
$result->failedRules()->count();                      // 1: limit
$result->eligibleRules()->count();                    // 2
$result->hasErrors();                                 // false
```

If the branches differ, the selected `all` root fails and its eligible collection is empty, even though the special-approval leaf still matches under normal evaluation. An empty group always fails. A root with no direct rules can pass through nonempty child groups.

## RuleResult

| Method       | Return type         | Meaning                                                                      |
| ------------ | ------------------- | ---------------------------------------------------------------------------- |
| `rule()`     | `Models\Rule`       | The evaluated Eloquent model, including name/expression/action fields.       |
| `passed()`   | `bool`              | The rule was enabled, its expression was truthy, and it raised no exception. |
| `failed()`   | `bool`              | The inverse of `passed()`, including disabled rules.                         |
| `disabled()` | `bool`              | The rule itself was disabled when visited; its expression was skipped.       |
| `error()`    | `Throwable \| null` | Runtime/parsing exception, or `null` for a normal true/false result.         |
| `message()`  | `string \| null` | Configured success text for a true condition, custom/default failure text for false; otherwise `null`. |

`Models\Rule::failure_message` and `success_message` are nullable text and mass assignable. `message()` snapshots the configured text for the actual true/false outcome. Ordinary false conditions without custom text use `Rule "{name}" did not pass.`; true conditions without success text return null. Disabled and expression-error results always return null. Messages are snapshots, separate from exceptions, and support no interpolation or HTML rendering. See [complete examples](examples/rules.md#rule-messages).

```php
foreach ($result->failedRules() as $ruleResult) {
    $name = $ruleResult->rule()->name;
    $error = $ruleResult->error();

    if ($error === null) {
        // Check disabled() first; otherwise a valid expression did not match.
    } else {
        // An expression could not be evaluated; inspect/log $error->getMessage().
    }
}
```

## ExecutionResult

Returned by `Rulecraft::group($name)->run($data)`. There is no `passed()` method on this class.

| Method         | Return type                 | Meaning                                                                                        |
| -------------- | --------------------------- | ---------------------------------------------------------------------------------------------- |
| `successful()` | `bool`                      | Evaluation passed and every attempted action succeeded. Passing with no actions is successful. |
| `failed()`     | `bool`                      | The inverse of `successful()`.                                                                 |
| `evaluation()` | `EvaluationResult`          | Condition results, unchanged by handler output/failure.                                        |
| `actions()`    | `Collection<ActionOutcome>` | Attempted actions in order, including the first failed action. Skipped actions are absent.     |
| `context()`    | `array`                     | Input plus successful action output; later top-level keys replace earlier values.              |

| Situation                                     | `evaluation()->passed()`      | `successful()`         | `actions()`                                  |
| --------------------------------------------- | ----------------------------- | ---------------------- | -------------------------------------------- |
| Group fails                                   | `false`                       | `false`                | Empty                                        |
| Group passes, no eligible handlers            | `true`                        | `true`                 | Empty                                        |
| All attempted actions succeed                 | `true`                        | `true`                 | All attempted outcomes                       |
| Handler returns failure or throws             | `true`                        | `false`                | Completed outcomes plus first failed outcome |
| Eligible handler missing/invalid/unresolvable | No `ExecutionResult` returned | An exception is thrown | No handlers invoked                          |

## ActionOutcome and ActionResult

| Object / method                           | Return type         | Meaning                                                              |
| ----------------------------------------- | ------------------- | -------------------------------------------------------------------- |
| `ActionOutcome::rule()`                   | `Models\Rule`       | Rule whose action was attempted.                                     |
| `ActionOutcome::result()`                 | `ActionResult`      | Handler success/failure information.                                 |
| `ActionOutcome::exception()`              | `Throwable \| null` | Original thrown exception. `null` for a returned failure or success. |
| `ActionResult::success(array $data = [])` | `ActionResult`      | Factory for a successful result with optional context output.        |
| `ActionResult::failure(string $error)`    | `ActionResult`      | Factory for a failure with an error string and empty output.         |
| `ActionResult::successful()`              | `bool`              | Whether this handler succeeded.                                      |
| `ActionResult::failed()`                  | `bool`              | The inverse of `successful()`.                                       |
| `ActionResult::data()`                    | `array`             | Successful output, or `[]` for failure.                              |
| `ActionResult::error()`                   | `string \| null`    | Failure message; `null` on success.                                  |

The non-factory methods above are instance methods. An exception from `handle()` becomes a failed `ActionResult` using the exception message, with the original exception on the outcome. A controlled `failure('reason')` has no exception. See the [complete handler example](examples/actions.md).

## PermissionResult

Returned by `Rulecraft::for($user)->permission($name)->evaluate($data)`.

| Method          | Return type                         | Meaning                                                                                              |
| --------------- | ----------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `passed()`      | `bool`                              | At least one actual role-permission grant has a passing mapped group.                                |
| `failed()`      | `bool`                              | The inverse of `passed()`.                                                                           |
| `evaluations()` | `Collection<int, EvaluationResult>` | Completed mapped-group evaluations keyed by mapping ID, not role or group name.                      |
| `hasErrors()`   | `bool`                              | At least one mapped evaluation/hierarchy lookup produced a collected error.                          |
| `errors()`      | `Collection<Throwable>`             | Expression/context-validation errors plus missing-group/invalid-hierarchy errors from mapped grants. |

No grant or no mapping produces a denied result and can leave both collections empty. An ordinary condition failure is also a denial without an error. A missing/invalid mapped group contributes an error without a completed evaluation entry. A separate valid grant can allow access while `hasErrors()` remains `true` for another grant. Inspect errors independently of the overall decision.

## Rule testing command

`php artisan rulecraft:test <group> --data='{"amount":100}'` prints the complete evaluated tree, ordinary failure messages, and expression errors. It uses evaluation only, without resolving action handlers. Exit codes: `0` for a passing group, `1` for a valid denial (including disabled selections), and `2` for invalid input or evaluation errors. A nonempty JSON string with an object root is required; file paths are not read. Quote the JSON for your shell. See the [complete command example](examples/policy-management.md#test-a-saved-policy-from-the-terminal).

## Policy import/export

`Rulecraft::export(string $group): string` returns pretty-printed JSON without a trailing newline: version 1 for expression-only trees, or version 2 when valid visual metadata is present. Version 2 adds nullable `builder_definition` to every rule. Both versions import; definitions must match their expressions. Both preserve optional nullable strings `failure_message` and `success_message`; imports default omitted fields to null and reject other types. It requires a root group, includes all descendant groups/rules in evaluation order, and validates the stored tree and expression syntax. `Rulecraft::import(string $json): Models\RuleGroup` validates the whole document, creates it in one transaction, and returns the saved root with `parent_id === null`. No evaluation or handler resolution occurs. Standard Eloquent persistence events still fire.

`Exceptions\InvalidPolicyException` extends `InvalidArgumentException`. It covers invalid JSON/schema/expression syntax, excessive import depth, duplicate document/database names, canceled model saves, and an attempt to export a child group. Wrapped JSON/expression errors retain their cause in `getPrevious()`. Missing exports throw `RuleGroupNotFoundException`; invalid stored hierarchy throws `InvalidGroupHierarchyException`. Database, configuration, and model-event exceptions propagate. No result object is returned when transfer throws.

`rulecraft:export <root>` prints only JSON plus a newline to stdout. `--output=<path>` creates a new file and prints confirmation; it refuses existing files/symlinks and requires an existing parent directory. `rulecraft:import --file=<path>` or `--json=<string>` accepts exactly one nonempty input. Both commands catch transfer failures, print errors to stderr, and return `2`; success returns `0`. They do not replace existing policies. See the [complete guide and schema](examples/policy-management.md#import-and-export-policies).

## Blade conditions

`@rulecraft($group, $context = [])` evaluates a group and tests its `passed()` value. `@canRulecraft($permission, $context = [], $guard = null)` checks the authenticated user's conditional permission; guests return `false`. The optional guard selects authentication and permission scope. Close them with `@endrulecraft` and `@endcanRulecraft`; use `@else` for a fallback. Neither runs actions or replaces Laravel's `@can`.

These are boolean view conditions, not result objects. See [group usage](examples/rules.md#show-a-group-decision-in-blade) and [permission usage](examples/permissions.md#show-a-conditional-permission-in-blade) for complete examples. Error handling follows the underlying evaluation, except guests are denied before grant resolution.

## Evaluation events

Events live in `Thuraaung\Rulecraft\Events` and use Laravel's event dispatcher. Public properties are PHPDoc-typed for PHP 7.3 compatibility.

| Event | Property | Type | Meaning |
| --- | --- | --- | --- |
| `GroupEvaluated` | `group` | `string` | Selected group name. |
| `GroupEvaluated` | `result` | `Results\EvaluationResult` | Completed condition result, including nested results and errors. |
| `GroupEvaluated` | `context` | `array<array-key, mixed>` | Original input before any action output. |
| `PermissionEvaluated` | `user` | `mixed` | User supplied to `Rulecraft::for()`. |
| `PermissionEvaluated` | `permission` | `string` | Selected permission name. |
| `PermissionEvaluated` | `guard` | `string \| null` | Explicit requested guard; `null` means default resolution was requested. |
| `PermissionEvaluated` | `result` | `Results\PermissionResult` | Final permission result with evaluated grants and collected errors. |
| `PermissionEvaluated` | `context` | `array<array-key, mixed>` | Original permission input. |

A group event fires once per completed public selection, rather than once per nested node. A permission check emits group events for each completed mapped-grant evaluation, then one permission event, including when no grant/mapping allows access. Repeated calls emit repeatedly. Guest Blade permission checks do not evaluate and emit nothing.

At dispatch, `result` is the same result object returned by the evaluation. Events include valid denials, empty/disabled states, and recorded expression/validation errors. An exception that prevents completion emits no completion event for that selection. Expression validation and JSON transfer emit no evaluation events.

`run()` emits its condition event before resolving or executing handlers. Action failures do not alter that emitted condition decision and produce no additional evaluation event. Listener return values are ignored by Rulecraft; they cannot replace the returned result. Array edits to event context do not change local evaluation input; nested objects and models remain shared references.

Synchronous listener exceptions follow normal evaluation exception boundaries: they can prevent return or action execution. Permission checks still collect missing-group/hierarchy exceptions per grant; other listener failures propagate. Listener errors are not `ActionOutcome` failures. No automatic audit storage, retry, or transaction is provided. See [listener examples and scope](examples/events.md).

## Builder

`<x-rulecraft::builder group="root-name" />` edits an existing root; omitting `group` starts a new policy. `:group="$groupName"` accepts a dynamic name string. The component requires builder enablement and the configured management ability. It merges root attributes and loads shared versioned assets once per page. It does not expose a standalone administration page.

Visual conditions start with visible Rule details (Name, Priority, Editor), then three full-width rows (Source, Operator, Compare with). Editable field types belong to Source; configured fields show a type badge, while functions show a typed signature beneath their selector. Literal types belong to Compare with. Messages, Action, and Generated expression are separate optional disclosure panels. Messages starts collapsed, contains both optional outcome text fields, and summarizes Defaults or the number customized. Each panel preserves its own open/closed state during redraws. New conditions receive a default group-unique `rule-N` name; changing it is optional. These presentation changes do not change the stored definition schema.

The internal JSON endpoints use the configured prefix, default `rulecraft/builder`, and Laravel route names below. Each requires the configured web/auth middleware and management ability; requests expect/return JSON. Writes use the application's CSRF token.

| Method and path | Route name | Input | Response |
| --- | --- | --- | --- |
| GET `policy` | `rulecraft.builder.load` | Query `group` name. | `{group, revision}`. |
| POST `validate` | `rulecraft.builder.validate` | `{group}` draft. | `{group}` with server-generated expressions. |
| POST `preview` | `rulecraft.builder.preview` | `{group, context}` with object context, or `context_json` string. | `{result}` nested decision/error data. |
| POST `save` | `rulecraft.builder.save` | `{group, revision}`; revision is required for existing roots. | Saved `{group, revision}`. |
| GET `assets/{file}` | `rulecraft.builder.asset` | Allowlisted `builder.js`, `builder.css`, or `bootstrap.css`. | Bundled file. |

Draft groups contain `id`, `name`, `match`, `enabled`, `rules`, and `groups`. Draft rules contain `id`, `name`, `expression`, optional nullable strings `failure_message` and `success_message`, `enabled`, `priority`, `action`, `action_parameters`, and nullable `builder_definition`. IDs are positive integer strings for existing nodes or `null` for new nodes. Existing IDs must remain under their original parent within the selected tree. Optional `action_parameters_json` text takes precedence over `action_parameters` to preserve JSON object/list/numeric distinctions through browsers.

A visual definition has one schema: `{source, operator, operand}`. It has no version field or legacy variants. Source shapes:

- Context field: `{type: "field", field: "loan_amount", value_type: "number"}`. The type is stored with the definition, so removing an optional field catalog does not force expression mode.
- Function: `{type: "function", name: "credit_score", return_type: "integer", arguments: {applicant_id: {type: "field", field: "applicant_id"}}}`.

New visual function selections bind arguments to context keys matching their declared names. Both function selectors show a read-only signature, such as `credit_score(int applicant_id): int`, with no separate function type or argument controls. Loaded definitions retain their actual bindings, including field mappings and literals supplied through the API. Displayed type annotations do not change expressions or cast values.

A comparison operand is a context field `{type: "field", field}`, a typed literal `{type: "value", value_type, value}`, or a function call using the same function shape as `source`. Function targets require a scalar comparison and a compatible return type; integer/number types may mix. Function arguments themselves accept only field or literal shapes. Literal `value_type` is required (`string`, `integer`, `number`, `boolean`, or `null` for nullable comparisons). Numeric values use strings to preserve integer/decimal precision; membership uses a list of those typed values. Function argument keys and order must match the provider's metadata. A zero-argument function uses an empty arguments map/list. Nested functions use expression mode.

The server validates source/type/operator choices and generates the expression, replacing client-supplied expression text. Raw rules have a null definition and preserve exact expression text. The outer policy import/export document has its own format version; it does not select a builder-definition schema.

`Contracts\BuilderExpressionProvider` extends Symfony's provider interface and adds `getBuilderFunctions(): array`. Its map is keyed by registered function name; each entry requires `label`, ordered `arguments` (name → `{label, type}`), and `return_type`. Supported scalar types are string/integer/number/boolean. Providers are container-resolved from `expression_providers`; no second registration is needed. Invalid provider metadata raises `InvalidArgumentException`, while invalid client function definitions raise `InvalidPolicyException` (`422` over HTTP). See the [provider example and metadata rules](examples/builder.md#custom-expression-providers).

Preview result groups contain `name`, `passed`, `disabled`, `has_errors`, `validation_errors`, `failed_message`, `passed_message`, `rules`, and `groups`. Group message strings use the same space-separated helper semantics. Each rule contains `name`, `passed`, `disabled`, nullable `message` for its true/false outcome, and nullable `error` for an exception. Preview uses transient models, with no policy writes, handler resolution, or normal evaluation-completion events. Registered expression extensions can still execute during preview.

Success returns `200`. Disabled builder access returns `404` after configured middleware runs; unauthenticated JSON requests normally return `401`, denied abilities `403`, missing named policies `404`, invalid input `422`, stale edits `409`, and invalid CSRF tokens the host's normal `419`. Infrastructure/configuration errors retain Laravel exception handling. `Exceptions\StalePolicyException` extends `InvalidPolicyException` and identifies a changed tree snapshot. Database/model-event exceptions still roll back the save and propagate.

Native model saves invalidate visual metadata when expressions diverge. Bulk updates bypass those hooks; builder loading/export detects stale definitions and falls back to raw code. Version 2 imports validate metadata without requiring the destination field catalog, retaining registered-function syntax checks. See [complete builder examples](examples/builder.md).

## Exceptions versus recorded failures

| Case                                                                   | Behavior                                                                                                                                             |
| ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| Configured context rules fail                                          | Failed `EvaluationResult` with `validationErrors()` and a collected Laravel `ValidationException`; that group's expressions/descendants are skipped. |
| Invalid schema configuration or unknown validation rule                | Configuration exception; not a normal input-denial result.                                                                                           |
| Disabled group/rule                                                    | Non-passing result with `disabled() === true`; no error solely because it is disabled. Disabled groups skip their subtree.                           |
| Non-matching expression                                                | Failed `RuleResult`, `error() === null`.                                                                                                             |
| Expression syntax/runtime error during evaluation                      | Failed `RuleResult` containing the original error; selected group fails.                                                                             |
| Invalid expression passed to `validateExpression()`                    | Throws `Exceptions\InvalidExpressionException`; original cause is available through `getPrevious()`.                                                 |
| Missing group in a direct group evaluation                             | Throws `Exceptions\RuleGroupNotFoundException`.                                                                                                      |
| Invalid group hierarchy in a direct evaluation/save                    | Throws `Exceptions\InvalidGroupHierarchyException`.                                                                                                  |
| Invalid action registration or eligible handler resolution             | Throws `Exceptions\InvalidActionHandlerException`; resolution errors preserve their cause.                                                           |
| Exception thrown inside a handler                                      | Recorded in a failed `ActionOutcome`; later handlers are skipped.                                                                                    |
| Invalid/empty API selection or `stopOnFailure()` on an `any` selection | Throws `InvalidArgumentException` (fail-fast mode is checked when evaluated).                                                                        |
| Missing/invalid mapped group in a permission check                     | Collected in `PermissionResult::errors()` for that grant.                                                                                            |

Database/connection failures and unexpected grant-resolver errors are not universally converted into decision results. Handle infrastructure exceptions at the application boundary. Never treat an exception or malformed expression as approval.
