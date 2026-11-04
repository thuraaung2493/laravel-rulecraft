# Expression reference

[Documentation home](../README.md) · [Configuration](configuration.md) · [Examples](examples.md)

Rulecraft uses Symfony Expression Language. Context keys become variables, and each expression is converted to a boolean decision. This page explains valid syntax, type behavior, parsing, and custom functions. Examples use the Symfony 5.4 baseline unless stated otherwise.

- [Variables and literals](#variables-and-literals)
- [Operators](#operator-reference)
- [Common invalid expressions](#common-invalid-expressions)
- [Nested data, missing values, and types](#nested-data-missing-values-and-types)
- [Validate before saving](#validate-before-saving)
- [Custom functions](#custom-functions)

## Variables and literals

Keys in the context array become expression variables. Use the variable name without `$`. Rulecraft does not automatically inject the authenticated user, request, database fields, or Laravel helpers.

```php
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RuleGroup;

$group = RuleGroup::create(['name' => 'green-zone', 'match' => 'all']);
$group->rules()->create([
    'name' => 'zone',
    'expression' => 'zone_type == "Green" and potential_risk_zone == 0',
]);

$result = Rulecraft::group('green-zone')->evaluate([
    'zone_type' => 'Green',
    'potential_risk_zone' => 0,
]);

$result->passed(); // true
```

Use quoted strings (`"Green"` or `'Green'`), numbers (`200000`, `12.5`), booleans (`true`, `false`), `null`, and arrays (`[2, 5, 8]`). Variable names are case-sensitive. Prefer consistent lowercase keywords and consistently typed input. For example, pass product IDs as integers in both context and lists.

There are two quoting layers when an expression is written in PHP:

```php
$expression = 'zone_type == "Green"';
// The expression stored in the database is: zone_type == "Green"
```

When storing JSON through an API, let the JSON encoder escape strings. Do not add PHP quotes around the stored expression itself.

## Operator reference

These examples use syntax available on the package's Symfony 5.4 baseline. Newer Symfony-only syntax should be checked against every version your application supports.

| Purpose | Syntax | Business example |
| --- | --- | --- |
| Equal / unequal | `==`, `!=` | `status == "pending"` |
| Equal / unequal with type | `===`, `!==` | `product_id === 2` |
| Ordered comparison | `<`, `<=`, `>`, `>=` | `loan_amount <= approval_limit` |
| Every condition | `and` or `&&` | `same_branch and active` |
| At least one condition | `or` or `\|\|` | `within_limit or special_approval` |
| Negation | `not` or `!` | `not blocked` |
| Grouping | `( ... )` | `same_branch and (within_limit or special_approval)` |
| In a list | `in` | `product_id in [2, 5, 8]` |
| Outside a list | `not in` | `status not in ["closed", "cancelled"]` |
| Numeric calculation | `+`, `-`, `*`, `/`, `%`, `**` | `subtotal - discount >= 1000` |
| Array key / item | `[...]` | `customer["country"] == "MM"` |
| Pattern match | `matches` | `reference matches "/^LN-[0-9]+$/"` |
| String concatenation | `~` | `(region ~ "-" ~ branch) == "North-A"` |
| Inclusive integer range | `..` | `age in 18..65` |
| Conditional value | `condition ? yes : no` | `amount <= (vip ? 500000 : 200000)` |

Use parentheses when combining AND and OR, arithmetic, or negation. For example, write `not (reference matches "/^LN-/")`. For a simple numeric interval, `age >= 18 and age <= 65` is also easy to read.

`==` can coerce types; `===` compares the type as well. List membership follows the installed Symfony/PHP comparison behavior rather than promising strict typed membership. Normalize values before evaluation; use a custom function when strict membership is a business requirement.

Other Symfony syntax, including bitwise operators and hash literals, remains available according to the installed dependency. The upstream [Symfony expression syntax reference](https://symfony.com/doc/5.x/reference/formats/expression_language.html) describes that language. This guide focuses on business conditions that work across Rulecraft's supported versions.

## Common invalid expressions

| Invalid input | Write this instead | Reason |
| --- | --- | --- |
| `zone_type = Green` | `zone_type == "Green"` | Equality uses `==`; text needs quotes. |
| `product_id include 2,5,8` | `product_id in [2, 5, 8]` | Membership uses `in` with an array. |
| `region_name in [Region 1- A]` | `region_name in ["Region 1- A"]` | Quote each string separately. |
| `$loan_amount <= $limit` | `loan_amount <= limit` | Context variables have no `$`. |
| `return amount > 0;` | `amount > 0` | Expressions contain no `return` or statement terminator. |
| `customer->country == "MM"` | `customer["country"] == "MM"` for array context | Use the expression language's access syntax. |
| `in_array(product_id, [2, 5])` | `product_id in [2, 5]` | Arbitrary PHP functions are not automatically registered. |

`include` means list membership in the examples above. For substring matching, use `matches` with an appropriate regular expression or register a named custom function; list membership does not search inside a string.

## Nested data, missing values, and types

```php
$expression = 'customer["country"] == "MM" and customer["verified"] == true';
$data = ['customer' => ['country' => 'MM', 'verified' => true]];
```

Provide every variable and array key the expression needs. An unknown variable causes an expression error; it is not automatically `null`. Missing array keys should not be used as a portable defaulting strategy because behavior can depend on the runtime/error handler. Normalize optional fields first:

```php
$data = [
    'income' => $input['income'] ?? null,
    'blocked' => (bool) ($input['blocked'] ?? false),
];
$expression = 'income != null and income >= 300000 and not blocked';
```

Objects support property access such as `customer.country` and callable methods such as `customer.isVerified()`. Arrays and scalars are easier to make predictable for stored business rules. Object methods and custom functions can perform work: the engine does not sandbox those methods or make them side-effect free.

Avoid relying on PHP truthiness. For example, the string `"false"` is truthy when a raw expression value is cast to `bool`. Write `approved == true` and supply a boolean, or `approved === true` when the type must be enforced.

## Validate before saving

```php
use Thuraaung\Rulecraft\Exceptions\InvalidExpressionException;

$expression = 'loan_amount <= approval_limit';

try {
    Rulecraft::validateExpression($expression, ['loan_amount', 'approval_limit']);
    // No return value on success. Persist the expression after validation.
} catch (InvalidExpressionException $error) {
    $message = $error->getMessage();
    // Present this validation failure to the rule author.
}
```

The second argument is a list of allowed variable names, not the context values. Parsing checks syntax, known functions, and variable names. It does not prove that values have the right types, that array keys exist, or that division will be safe at runtime. It does not execute the expression or business functions. Creating or updating a `Rule` does not automatically call this validator.

At runtime, expression exceptions are recorded in `RuleResult::error()` and the selected group fails. Inspect [the nested result example](examples/rules.md#read-nested-results) to distinguish invalid expressions from ordinary non-matching conditions.

## Custom functions

Put this class in your application's `app/Rules/CreditFunctions.php`:

```php
namespace App\Rules;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;

class CreditFunctions implements ExpressionFunctionProviderInterface
{
    public function getFunctions(): array
    {
        return [new ExpressionFunction(
            'credit_eligible',
            function ($score) {
                return '('.$score.' >= 650)';
            },
            function (array $variables, $score) {
                return $score >= 650;
            }
        )];
    }
}
```

Register it in `config/rulecraft.php` before parsing/evaluation:

```php
'expression_providers' => [App\Rules\CreditFunctions::class],
```

Then use `credit_eligible(credit_score)` with `['credit_score' => 700]`. The first callback describes compilation; the second evaluates the function and receives the complete context before its expression arguments. Rulecraft's public engine evaluates expressions, and Symfony requires both callbacks when defining a function. Providers are resolved through Laravel's container and can receive constructor dependencies.

Rulecraft adds no general-purpose PHP function provider. Symfony's own built-ins vary by version (`constant()` exists on the baseline); use named providers for application functions instead of assuming helpers such as `auth()`, `now()`, or `count()` are available. Rebuild Laravel's config cache after changing configured providers. The [Blade builder](examples/builder.md#custom-expression-providers) supports visual comparisons and custom functions with optional provider metadata, plus expression-mode fallback; automatic expression-to-visual conversion remains deferred.

Use the [rule examples](examples/rules.md) for complete policies and the [API reference](api-reference.md) for result types and exceptions.
