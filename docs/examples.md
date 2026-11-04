# Use cases and examples

[Documentation home](../README.md) · [API reference](api-reference.md) · [Configuration](configuration.md)

Use the README for a short introduction to every core feature. These examples provide complete setup, sample input, expected results, and failure behavior for a particular task. Create each policy once; evaluate saved records in requests.

| Scenario | Example | What you will learn |
| --- | --- | --- |
| Visual policy authoring | [Blade builder](examples/builder.md) | Component embedding, nested editing, preview/save, and CSS customization. |
| Loan approval | [Nested loan policy](examples/rules.md#nested-loan-approval) | Same branch AND (within limit OR special approval), with child results. |
| Zone/product eligibility | [Zone, risk, and product policy](examples/rules.md#zone-risk-and-product-policy) | Several AND branches under an OR root, string lists, and all supplied product IDs. |
| Order promotion | [Promotion conditions](examples/rules.md#order-promotion) | Nested customer data, strict booleans, and minimum order value. |
| Explain rule outcomes | [Rule messages](examples/rules.md#rule-messages) | Success/failure text, combined group messages, defaults, Builder editing, and technical errors. |
| Group decisions in views | [Blade condition](examples/rules.md#show-a-group-decision-in-blade) | Show content from a business decision. |
| Action handlers | [Record a loan decision](examples/actions.md) | A complete handler, registration, multiple actions, output context, and failures. |
| Role-based conditional approval | [Spatie permissions](examples/permissions.md) | An actual Branch Manager grant, condition mapping, guards, teams, and Blade. |
| Validate policy input | [Context validation](examples/policy-management.md#validate-context) | Required/type/nested fields and field-error results. |
| Suspend a policy or rule | [Enabled states](examples/policy-management.md#enable-or-disable-policies) | ALL/ANY decisions, disabled ancestors, and preserved results. |
| Debug from the terminal | [Policy testing command](examples/policy-management.md#test-a-saved-policy-from-the-terminal) | JSON context, nested output, and exit codes without actions. |
| Observe decisions | [Evaluation listeners](examples/events.md) | Group/permission payloads, logging examples, timing, and failures. |
| Move policies between environments | [Import/export](examples/policy-management.md#import-and-export-policies) | JSON strings, files, clipboard, schema, duplicate names, and transactional saves. |

The [expression reference](expressions.md) explains valid operators and custom functions. The [API reference](api-reference.md) lists all return types and exceptions.
