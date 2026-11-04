# Evaluation event examples

[Documentation home](../../README.md) · [All examples](../examples.md) · [API reference](../api-reference.md#evaluation-events)

Use Laravel listeners to observe completed decisions for application logging, auditing, or metrics. Rulecraft dispatches `GroupEvaluated` and `PermissionEvaluated`; the application chooses what to record and where. No audit tables, storage backend, or metrics service are installed.

- [Log a group decision](#log-a-group-decision)
- [Observe a permission decision](#observe-a-permission-decision)
- [Event timing and scope](#event-timing-and-scope)
- [Listener behavior](#listener-behavior)

## Log a group decision

Register this closure in your application's service provider `boot()` method:

```php
use Illuminate\Support\Facades\Event;
use Thuraaung\Rulecraft\Events\GroupEvaluated;

Event::listen(GroupEvaluated::class, function (GroupEvaluated $event): void {
    logger()->info('Rulecraft group evaluated', [
        'group' => $event->group,
        'loan_id' => $event->context['loan_id'] ?? null,
        'passed' => $event->result->passed(),
        'disabled' => $event->result->disabled(),
        'has_errors' => $event->result->hasErrors(),
    ]);
});
```

Create a sample policy once, then evaluate it:

```php
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RuleGroup;

$group = RuleGroup::create(['name' => 'event-demo', 'match' => 'all']);
$group->rules()->create([
    'name' => 'within-limit',
    'expression' => 'loan_amount <= approval_limit',
]);

$result = Rulecraft::group('event-demo')->evaluate([
    'loan_id' => 42,
    'loan_amount' => 300000,
    'approval_limit' => 200000,
]);
$result->passed(); // false
```

The listener receives one event with `group === 'event-demo'`, `loan_id === 42` in `context`, and a result whose `passed()` is `false`, `disabled()` is `false`, and `hasErrors()` is `false`. The result is the same object returned by `evaluate()`. Increasing the limit to `400000` produces a passing event on the next evaluation.

Invalid expression context, such as omitting `loan_amount` without a validation schema, still completes with a recorded expression error: `passed()` is `false` and `hasErrors()` is `true`. Optional validation failures expose `validationErrors()`. Disabled groups emit a denied result with `disabled() === true` and no visited descendants.

For a class-based listener with injected dependencies, save this as `app/Listeners/LogGroupDecision.php`:

```php
namespace App\Listeners;

use Psr\Log\LoggerInterface;
use Thuraaung\Rulecraft\Events\GroupEvaluated;

class LogGroupDecision
{
    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle(GroupEvaluated $event): void
    {
        $this->logger->info('Rulecraft decision', [
            'group' => $event->group,
            'passed' => $event->result->passed(),
            'has_errors' => $event->result->hasErrors(),
        ]);
    }
}
```

Register the class in your provider's `boot()` method instead of the earlier closure if you want a single log per group event:

```php
use App\Listeners\LogGroupDecision;
use Illuminate\Support\Facades\Event;
use Thuraaung\Rulecraft\Events\GroupEvaluated;

Event::listen(GroupEvaluated::class, LogGroupDecision::class);
```

Laravel resolves the listener and its constructor dependencies. Choose your own fields, storage, retention, and metrics conventions in application listeners.

## Observe a permission decision

Register a separate listener for the final role-permission decision:

```php
use Illuminate\Support\Facades\Event;
use Thuraaung\Rulecraft\Events\PermissionEvaluated;

Event::listen(PermissionEvaluated::class, function (PermissionEvaluated $event): void {
    logger()->info('Rulecraft permission evaluated', [
        'permission' => $event->permission,
        'requested_guard' => $event->guard,
        'loan_id' => $event->context['loan_id'] ?? null,
        'passed' => $event->result->passed(),
        'has_errors' => $event->result->hasErrors(),
        'evaluated_grants' => $event->result->evaluations()->count(),
    ]);
});
```

After the [complete Spatie setup](permissions.md), this call emits group events for evaluated mapped grants, then one permission event:

```php
$decision = Rulecraft::for($user)
    ->permission('Loan Approval')
    ->guard('web')
    ->evaluate($context);
```

The permission event contains the supplied `$user`, permission name `Loan Approval`, requested guard `web`, original context, and the same `PermissionResult` returned by the call. Omitting `guard()` leaves the event's `guard` as `null`, meaning normal default guard resolution was requested; it is not a resolved guard-name snapshot.

No actual grant or no matching mapping still produces one denied permission event, possibly with no group events. Multiple grants are evaluated independently: one may authorize while another contributes errors. Inspect `hasErrors()` separately from `passed()`. Missing-group/hierarchy errors collected for a grant appear in the final permission result even if that grant produced no completed group result.

## Event timing and scope

| Operation | Events |
| --- | --- |
| `group($name)->evaluate($data)` | One `GroupEvaluated` after the selected tree completes. Children are nested results, not separate events. |
| Direct child selection | One group event for that child selection, honoring disabled ancestors. |
| `group($name)->run($data)` | The same group event before any action handler is resolved or invoked. It describes conditions, not action success. |
| Permission `evaluate($data)` | A group event for each completed mapped-grant evaluation, followed by one final `PermissionEvaluated`. |
| Repeated checks, including Blade or CLI | Events for each completed evaluation; there is no deduplication across calls. |
| Guest `@canRulecraft` | No evaluation and no event; the directive returns `false` first. |
| `validateExpression()`, `export()`, or `import()` | No evaluation events. Normal Eloquent persistence events still apply to imports. |
| Exception before a result is completed | No completion event for that selection. Previously completed grant events may already have fired. |

Passing, denied, empty, disabled, expression-error, and context-validation-error results all emit completion events. Fail-fast results contain only visited nodes. Missing groups, invalid selections/hierarchies, or infrastructure/configuration errors that abort evaluation do not create synthetic completion results.

With two mapped grants and a listener for each event type, expect two group notifications and one final permission notification when both grants complete. To count permission requests once, use the permission event. To inspect each group's conditions, use group events. Neither event represents a successful loan action or an execution outcome.

## Listener behavior

Listener return values do not replace a decision or its result. Laravel's normal event rules still apply, including stopping later listeners when one returns `false`. The event context is the input array before actions; modifying its array values does not change Rulecraft's local input. Objects and result models remain shared references, so treat payloads as observations rather than edits to policy state.

The examples use synchronous listeners. A throwing listener follows the existing exception boundaries and can prevent a result from reaching the caller; a group listener can stop `run()` before actions start. Permission evaluation retains its per-grant collection of `RuleGroupNotFoundException` and `InvalidGroupHierarchyException`; other listener exceptions propagate. Listener failures are not action outcomes, and Rulecraft does not retry them.

Handle expected logging/metrics failures within your listener if evaluation should continue. For deferred work, dispatch an application job containing the specific scalar fields you need. Rulecraft provides the notifications; the application's queueing, transactions, and persistence choices remain application-owned.
