# Rule examples

[Documentation home](../../README.md) · [All examples](../examples.md)

These complete examples cover nested loan approval, zone/product eligibility, and order promotions. Each includes setup, input data, and expected outcomes. Create each policy once in a seeder; evaluate it in application requests. Group names are globally unique.

- [Nested loan approval](#nested-loan-approval)
- [Read nested results](#read-nested-results)
- [Rule messages](#rule-messages)
- [Show a group decision in Blade](#show-a-group-decision-in-blade)
- [Zone, risk, and product policy](#zone-risk-and-product-policy)
- [Order promotion](#order-promotion)
- [Traversal, fail-fast, and hierarchy limits](#group-traversal-and-limits)

`all` requires every direct rule and child group to pass; `any` requires at least one. A group can contain either or both. Empty groups fail. Any error in the evaluated tree fails the selected group, even if another branch matches.

## Nested loan approval

Policy: `same branch AND (within limit OR special approval)`.

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

$result = Rulecraft::group('loan-approval')->evaluate($data);
$result->passed(); // true
$result->matchedRules()->count(); // 2: same-branch, special
$result->failedRules()->first()->rule()->name; // 'limit'
```

| Branches match | Within limit | Special approval | Root passes |
| --- | --- | --- | --- |
| Yes | Yes | No | Yes |
| Yes | No | Yes | Yes |
| Yes | No | No | No |
| No | No | Yes | No |

Evaluating `Rulecraft::group('limit-or-special')->evaluate($data)` checks only that subtree and passes here. It does not enforce `same-branch`. Use the root for the complete policy; permission assignments must reference a root.

## Read nested results

`evaluate()` returns an `EvaluationResult`. For the successful loan example above:

```php
$result->passed(); // true
$result->rules()->count(); // 1: direct same-branch rule
$result->groups()->get('limit-or-special')->passed(); // true
$result->allRules()->count(); // 3: direct and descendant rule results
$result->matchedRules()->count(); // 2: same-branch, special
$result->failedRules()->first()->rule()->name; // 'limit'
$result->eligibleRules()->count(); // 2: passing leaves on passing paths
$result->hasErrors(); // false
$result->errors()->count(); // 0
```

A rule result exposes `rule()` (the Eloquent model), `passed()`, `failed()`, and `error()` (`Throwable|null`). An ordinary false condition has no error. `groups()` contains immediate child evaluations keyed by name; rule collections contain result objects rather than raw models.

`matchedRules()` includes matching leaves even inside failed branches. `eligibleRules()` includes only leaves with a fully passing path to the selected group and is empty if that group failed. It can include leaves without an action. This distinction determines which handlers `run()` can invoke. Reading a child result describes that subtree; it does not add parent conditions.

## Rule messages

Store `failure_message` for a normal false condition and optional `success_message` for a true condition. This works with visual definitions, raw expressions, and custom functions:

```php
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RuleGroup;

$group = RuleGroup::create(['name' => 'manager-approval', 'match' => 'all']);
$group->rules()->create([
    'name' => 'approval-limit',
    'expression' => 'loan_amount <= approval_limit',
    'failure_message' => 'The loan amount exceeds your approval limit.',
    'success_message' => 'The loan amount is within your approval limit.',
]);

$result = Rulecraft::group('manager-approval')->evaluate([
    'loan_amount' => 300000,
    'approval_limit' => 200000,
]);
$failure = $result->failedRules()->first();
$failure->message(); // 'The loan amount exceeds your approval limit.'
$failure->error(); // null: a valid expression returned false
```

| Rule outcome | `message()` | `error()` |
| --- | --- | --- |
| False, with nonblank custom text | Stored message | `null` |
| False, with null/empty/whitespace-only text | `Rule "approval-limit" did not pass.` | `null` |
| True, with nonblank success text | Stored success message | `null` |
| True, without success text | `null` | `null` |
| Disabled | `null` | `null` |
| Expression error | `null` | Original exception |

Messages are plain text, with no expression parsing, placeholders, context interpolation, or HTML rendering. Translate a message in the application before storing it when appropriate. Escape text when displaying it in your own views, for example `{{ $failure->message() }}`. Builder preview and the testing command display messages as text. Changing a rule after evaluation does not change the result's message snapshot.

In the Builder, open the **Messages** panel and edit **Failure message (optional)** and **Success message (optional)**. The panel starts collapsed like Action, displays the number of customized messages in its summary, and remembers its open/closed state during editing. Both apply in Visual and Expression modes. Clearing failure text restores the default; clearing success text makes a passing rule silent. Preview shows each rule's outcome message and a combined group message. Saving, reloading, and JSON import/export preserve both fields. Both policy JSON formats accept optional string or null `failure_message` and `success_message`; omission means null. Invalid types are rejected before saving.

### Combined group messages

Both helpers return a string and use a space separator by default:

```php
$message = $result->passed()
    ? $result->passedMessage()
    : $result->failedMessage();

$result->failedMessage(' • '); // Custom separator
$result->passedMessage('; ');
```

For three failed rules with messages `The branches must match.`, `The loan amount exceeds your approval limit.`, and `Special approval is required.`, `failedMessage()` returns:

```text
The branches must match. The loan amount exceeds your approval limit. Special approval is required.
```

`failedMessage()` collects ordinary failure messages from all visited direct/nested rules, in evaluation order. `passedMessage()` uses `eligibleRules()`: only passing rules whose complete path to this selected group passed. It excludes passing leaves inside failed branches and returns an empty string if the selected group failed. On a child result, that child is the selected subtree; parent conditions are not added.

No contributing messages means `''`. Empty/whitespace-only success text contributes nothing; failure text retains its default. Separators apply only to that call, do not change stored text, and do not supply punctuation. Write each message as a complete sentence. Repeated text is retained because different rules may share a message. Fail-fast aggregation includes only visited rules.

After `run()`, access these helpers through `$execution->evaluation()`; they describe conditions, while `$execution->successful()` also checks action outcomes.

Always check the selected group's decision first. An ANY group can pass while one alternative has a failure message. `failedRules()` also includes disabled/error results, whose `message()` is null. Context validation can fail before any rule is visited; inspect `validationErrors()` on that group instead.

## Show a group decision in Blade

Use `@rulecraft` to show content when the named group passes. For the loan policy above, pass the same `$data` array from the controller to the view:

```blade
@rulecraft('loan-approval', $data)
    <button type="submit">Approve loan</button>
@else
    <p>Approval conditions are not met.</p>
@endrulecraft
```

This calls `group('loan-approval')->evaluate($data)->passed()` and never executes handlers. Context is optional and defaults to `[]`: `@rulecraft('always-available')`. Provide all variables required by the selected group. A false condition, invalid context, or expression error renders the else branch; a missing group or invalid hierarchy keeps the normal exception behavior rather than silently hiding a configuration problem.

Use this directive for business conditions regardless of authentication. It does not check a user's role or permission. Visibility is only a view decision; enforce the same policy in the controller or service before performing the operation. Repeated directives perform separate evaluations; pass a previously evaluated result to the view and use `@if($result->passed())` when several sections share a decision.

## Zone, risk, and product policy

Policy:

```text
(zone_type = Green AND potential_risk_zone = 0)
OR (potential_risk_zone = 1 AND region_name is in the approved regions)
OR (product_id is in the approved products)
```

Create an `any` root containing `all` branches. Each comparison stays its own rule; the root needs no direct rules.

```php
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RuleGroup;

$root = RuleGroup::create(['name' => 'zone-product-eligibility', 'match' => 'any']);

$green = $root->groups()->create(['name' => 'green-low-risk', 'match' => 'all']);
$green->rules()->createMany([
    ['name' => 'green-zone', 'expression' => 'zone_type == "Green"'],
    ['name' => 'low-risk', 'expression' => 'potential_risk_zone == 0'],
]);

$regional = $root->groups()->create(['name' => 'approved-risk-regions', 'match' => 'all']);
$regional->rules()->createMany([
    ['name' => 'risk-zone', 'expression' => 'potential_risk_zone == 1'],
    [
        'name' => 'region',
        'expression' => 'region_name in ["Region 1- A", "Region 1- B", "Region 2"]',
    ],
]);

$product = $root->groups()->create(['name' => 'approved-products', 'match' => 'all']);
$product->rules()->create([
    'name' => 'product',
    'expression' => 'product_id in [2, 5, 8, 11, 18, 19, 21, 25, 26, 30, 31, '
        .'36, 37, 38, 39, 43, 44, 45, 48, 49, 60, 62, 63, 64, 87, 88, 92]',
]);

$result = Rulecraft::group('zone-product-eligibility')->evaluate([
    'zone_type' => 'Red',
    'potential_risk_zone' => 1,
    'region_name' => 'Region 1- B',
    'product_id' => 999,
]);

$result->passed(); // true: approved-risk-regions passes
$result->rules()->count(); // 0: root has only child groups
$result->groups()->get('approved-risk-regions')->passed(); // true
$result->allRules()->count(); // 5
$result->eligibleRules()->count(); // 2: risk-zone, region
```

With `Red`, risk `0`, an unlisted region, and product `999`, every branch fails. Changing only the product to `92` allows the product branch. Region strings match their actual spelling and spacing; normalize data if your policy needs case-insensitive or whitespace-insensitive comparisons.

All required fields should still be present when another branch is expected to pass: normal evaluation visits the full tree, and an expression error in one branch fails the selected root even when another branch matches.

## Order promotion

Policy: a verified customer in an eligible country with an order subtotal of at least 100000.

```php
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RuleGroup;

$group = RuleGroup::create(['name' => 'order-promotion', 'match' => 'all']);
$group->rules()->createMany([
    ['name' => 'country', 'expression' => 'customer["country"] in ["MM", "TH"]'],
    ['name' => 'verified', 'expression' => 'customer["verified"] === true'],
    ['name' => 'minimum-subtotal', 'expression' => 'subtotal >= 100000'],
]);

$result = Rulecraft::group('order-promotion')->evaluate([
    'customer' => ['country' => 'MM', 'verified' => true],
    'subtotal' => 150000,
]);

$result->passed(); // true
$result->failedRules()->count(); // 0
```

This check does not calculate or apply a discount. Use its decision in application code, or attach a registered action and call `run()` as shown in the [action guide](actions.md). Reducing the subtotal to 50000 produces an ordinary failed condition with no expression errors.

## Group traversal and limits

Normal evaluation visits every node. To stop at the first failure in an `all` selection:

```php
$pending = Rulecraft::group('loan-approval');
$result = $pending->stopOnFailure()->evaluate($data);
// $pending remains unchanged; only visited rules/groups appear in $result.
```

Using `stopOnFailure()` on an `any` selection throws `InvalidArgumentException` when evaluated. A missing named group throws `RuleGroupNotFoundException` instead of returning an evaluation result.

`parent()` and `groups()` are standard Eloquent relationships. Each child has one parent; names remain globally unique. All nodes receive the same input data. Each group evaluates its direct rules by descending priority then ascending ID, followed by child groups in ascending ID, recursively. Actions follow that same traversal order. Sibling branches do not share condition results or change each other's evaluation input.

`stopOnFailure()` on an `all` root also short-circuits its nested `all` groups. Nested `any` groups still evaluate all their children. Only visited nodes appear in the result when evaluation stops early. Calling `group()` directly with a child's name evaluates that subtree; its parent's conditions are not inherited, but disabled ancestors still block evaluation. Conditional permission assignments therefore require a root group.

The default `rulecraft.max_depth` is **10**, counting the root as level one. Set a positive integer in `config/rulecraft.php` to change it. Eloquent saves reject circular parents, missing parents, and moves that put any descendant beyond the limit. Runtime checks also protect evaluation after direct database changes or a lowered limit, throwing `InvalidGroupHierarchyException` for invalid hierarchies. Deleting a group cascades to its descendants, rules, and mappings.

The single initial migration creates parent links, enabled states, indexes, and foreign keys together, using your configured table names. Schema creation includes the self-referencing parent constraint on SQLite and the other supported drivers. Rolling back the migration removes all three package tables and their records; running it again creates an empty schema.

See the [expression reference](../expressions.md) for syntax and the [API reference](../api-reference.md) for exhaustive method types.
