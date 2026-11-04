# Running actions

[Documentation home](../../README.md) · [All examples](../examples.md)

Use `evaluate()` to check conditions. Use `run()` when a passing decision should invoke handlers. Each rule has one optional `action` name and an `action_parameters` array. Several eligible rules can invoke several actions, including the same registered handler with different parameters.

- [A complete handler](#a-complete-handler)
- [Register the class](#register-the-class)
- [Create a rule and run it](#create-a-rule-and-run-it)
- [Read the execution result](#read-the-execution-result)
- [Multiple actions and output context](#multiple-actions-and-output-context)
- [Handle failures](#handle-failures)

## A complete handler

This example records a decision in the application's log and returns context for later handlers. It does not update a loan's database status. Save it as `app/Actions/RecordLoanDecision.php` in the consuming application:

```php
namespace App\Actions;

use Psr\Log\LoggerInterface;
use Thuraaung\Rulecraft\Actions\ActionResult;
use Thuraaung\Rulecraft\Contracts\Action;

class RecordLoanDecision implements Action
{
    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param array<array-key, mixed> $parameters
     * @param array<array-key, mixed> $context
     */
    public function handle(array $parameters, array $context): ActionResult
    {
        $decision = $parameters['decision'] ?? null;
        $loanId = $context['loan_id'] ?? null;

        if (! in_array($decision, ['approved', 'manual-review'], true)) {
            return ActionResult::failure('Unsupported loan decision.');
        }

        if ($loanId === null) {
            return ActionResult::failure('A loan_id is required to record the decision.');
        }

        $this->logger->info('Loan decision recorded', [
            'loan_id' => $loanId,
            'decision' => $decision,
        ]);

        return ActionResult::success([
            'decision' => $decision,
            'decision_recorded' => true,
        ]);
    }
}
```

Laravel resolves constructor dependencies. `handle()` receives stored rule parameters separately from runtime context and must return `ActionResult`. It needs no `supports()` method. Return a failure for expected business failures. Unexpected exceptions are captured by Rulecraft as failed action outcomes.

## Register the class

In `App\Providers\AppServiceProvider::boot()` (or a module provider's `boot()`):

```php
use App\Actions\RecordLoanDecision;
use Thuraaung\Rulecraft\Facades\Rulecraft;

public function boot(): void
{
    Rulecraft::registerAction('record_loan_decision', RecordLoanDecision::class);
}
```

Alternatively, add the name/class mapping in `config/rulecraft.php`:

```php
'actions' => [
    'record_loan_decision' => App\Actions\RecordLoanDecision::class,
],
```

Choose one registration location for each name. Config and provider registration share an application-scoped registry. Duplicate names throw `InvalidActionHandlerException`, even when both registrations use the same class. Provider registration rejects blank names and classes that do not implement `Contracts\Action`. Registration stores class names without constructing instances; handlers are resolved when preparing execution.

Provider registration works with Laravel's cached configuration and runs at every application boot. Rebuild the config cache after editing config-based registrations. Explicit providers let each module own its registrations without growing a central config file. There is no directory scanning or database-defined handler class registration.

## Create a rule and run it

Run this setup once, for example in a seeder. Register the handler before executing it:

```php
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RuleGroup;

$group = RuleGroup::create(['name' => 'record-small-loan', 'match' => 'all']);
$group->rules()->create([
    'name' => 'within-limit',
    'expression' => 'loan_amount <= approval_limit',
    'action' => 'record_loan_decision',
    'action_parameters' => ['decision' => 'approved'],
]);

$input = ['loan_id' => 42, 'loan_amount' => 100000, 'approval_limit' => 200000];

$check = Rulecraft::group('record-small-loan')->evaluate($input);
$check->passed(); // true; no decision log was written by a handler

$execution = Rulecraft::group('record-small-loan')->run($input);
$execution->evaluation()->passed(); // true
$execution->successful();          // true
$execution->actions()->count();    // 1
$execution->context();
// [
//     'loan_id' => 42,
//     'loan_amount' => 100000,
//     'approval_limit' => 200000,
//     'decision' => 'approved',
//     'decision_recorded' => true,
// ]

$outcome = $execution->actions()->first();
$outcome->rule()->name;        // 'within-limit'
$outcome->result()->data();    // ['decision' => 'approved', 'decision_recorded' => true]
$outcome->result()->error();   // null
$outcome->exception();         // null
```

With `loan_amount` above the limit, evaluation fails, no handler is invoked, `successful()` is `false`, and `actions()` is empty. With an amount within the limit but no `loan_id`, evaluation passes and the handler returns a controlled failure: `successful()` is `false`, its result's `error()` contains the missing-ID message, and its `exception()` is `null`.

`run()` also emits a `GroupEvaluated` event for its completed condition check before resolving handlers. It is not an action-success event. Application listeners may perform work or throw; see [evaluation events](events.md#listener-behavior) for timing and failure behavior.

## Read the execution result

`run()` returns `ExecutionResult`, not a boolean. Test `successful()` explicitly; testing the object itself does not check whether execution succeeded.

| Method | What it returns |
| --- | --- |
| `successful()` / `failed()` | Whether conditions passed and every attempted action succeeded, or its inverse. A passing policy with no actions is successful. |
| `evaluation()` | The condition `EvaluationResult`; action failures do not change its decision. |
| `actions()` | Ordered `ActionOutcome` collection of attempted handlers, including the first failure. Skipped handlers are absent. |
| `context()` | Original input plus successful handler output. |

Each outcome exposes `rule()`, `result()`, and `exception()`. Its `ActionResult` exposes `successful()`, `failed()`, `data()` (output array), and `error()` (failure string or `null`). A returned failure has empty output and no exception. A thrown handler exception becomes a failure with the exception's message and the original `Throwable` on the outcome.

## Multiple actions and output context

Direct rules run by descending `priority`, then ascending rule ID. Child groups follow in ascending group ID, recursively. Action execution follows the same traversal after the selected group passes. Priority orders direct rules within their group; it is not a global sort across the tree.

For a second handler to consume `decision_recorded`, store its rule after the first, or give the first a higher priority within the same group. The second handler reads the accumulated context in `handle()`:

```php
if (($context['decision_recorded'] ?? false) !== true) {
    return ActionResult::failure('Record the decision before notifying.');
}
```

All conditions are evaluated against the original input before any handler runs. Do not put `decision_recorded == true` in the second rule expecting the first action's output to satisfy it. Use a condition based on original input, and check execution prerequisites in the handler.

Successful output is merged with `array_replace`: later top-level keys overwrite earlier ones, nested arrays are replaced entirely, and the caller's original input array is unchanged. Parameters are stored arrays, not interpolated expression templates; a parameter value such as `"loan_amount"` remains a string unless your handler interprets it.

All eligible handlers are resolved before invoking the first `handle()`. Missing, invalid, or unresolvable eligible handlers throw `InvalidActionHandlerException`. Constructors should avoid side effects because constructor resolution occurs during preparation.

Disabled groups and rules never run actions. A disabled selected group or ancestor produces a failed execution with no attempted actions. Disabled rules count as non-passing conditions, so an ALL group cannot bypass them.

A leaf is eligible only when it matches and every group on its path to the selected group passes. A matching leaf in a failed subgroup cannot run just because another subgroup makes an `any` root pass. A passing selection with no eligible actions returns a successful execution with an empty actions collection.

## Handle failures

```php
if ($execution->failed()) {
    if ($execution->evaluation()->failed()) {
        // Inspect failedRules(), validationErrors(), and errors() on evaluation().
    } else {
        $outcome = $execution->actions()->last();
        $message = $outcome->result()->error();
        $exception = $outcome->exception();
        // A handler failed; decide whether/how the application should recover.
    }
}
```

A returned failure or thrown exception stops subsequent handlers. Completed outcomes and successful output remain available. There is no automatic rollback, transaction, retry, queueing, or idempotency guarantee. Each `run()` is a new execution; implement those requirements in the application/handlers. Permission checks never execute actions, so a permission decision must be followed by an explicitly authorized application operation.

The [return reference](../api-reference.md) documents `ExecutionResult`, `ActionOutcome`, and `ActionResult` in full.
