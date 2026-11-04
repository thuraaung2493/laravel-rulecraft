# Rulecraft Project Plan

Status: Engine, conditional permissions, evaluation events, and initial Blade builder implemented
Created: 2026-10-30
Project directory: `~/dev/packages/laravel-rulecraft`
Previous implementation: archived locally before the Rulecraft redesign.

## Implementation status (2026-11-02)

Implemented in `src/`, with usage in `README.md`:

- Rulecraft Builder implemented (2026-11-01): optional `<x-rulecraft::builder>` component, protected load/validate/preview/save endpoints, configurable field catalog, typed literals and field references, exact raw-expression fallback, nullable rule metadata, and shared in-memory evaluation. Saving retains IDs/mappings, rejects stale revisions and foreign/reparented IDs, and rolls back failed/canceled persistence. Bundled custom and scoped Bootstrap 5.3.8 presets support CSS variables, an application stylesheet, and publishable views. Assets load once with content hashes. JSON transfer uses version 2 only when visual metadata exists; version 1 remains supported. Twenty-eight integration tests and browser checks cover behavior and integration. Automatic reverse conversion, persistent drafts, history, drag-and-drop, and a separate admin shell remain deferred.
- Initial schema consolidated (2026-10-31): one migration creates all three configured package tables, including hierarchy, enabled states, indexes, and cascade constraints. Removed the two incremental migrations because this is a fresh unpublished package. Tests cover one-step rollback, empty-schema recreation, hierarchy cascades, state defaults, and custom table names.
- Evaluation events implemented (2026-10-31): GroupEvaluated emits once per completed public group selection, including run() before handler resolution. Permission checks emit completed grant-group events followed by one PermissionEvaluated, including denied results without grants/mappings. Events carry completed results and original context; permission events include the supplied user, permission, and requested guard. Nested nodes stay in the result; aborted evaluations, expression validation, JSON transfer, and guest Blade permission shortcuts emit no completion event. Twenty integration tests cover payloads, ordering, pass/deny/error/disabled/fail-fast outcomes, action timing, listener behavior, grants, CLI, and Blade. No audit storage or metrics backend is installed.
- Policy import/export implemented (2026-10-31): `Rulecraft::export($group): string` and `Rulecraft::import($json): RuleGroup`, plus Artisan JSON/file commands. Strict version 1 documents preserve complete root trees, state, priority/order, action names, and parameter object/list shapes. Syntax checks never execute expressions/handlers. Imports validate before writes and save transactionally, rejecting duplicate names or canceled saves without replacing existing policies. Exports emit raw JSON to stdout for `pbcopy`; optional file output refuses existing paths. Twenty-eight integration tests cover round trips, malformed policies, duplicate names, syntax/depth, action isolation, rollback, CLI streams/files, and custom tables. No handler code, IDs, config, or permission mappings are transferred.
- Policy states implemented (2026-10-31): groups/rules have `enabled` booleans defaulting to true in the consolidated initial schema. Disabled nodes count as false; disabled groups skip context validation and descendants, and disabled ancestors block direct subtree selection. Results expose snapshot `disabled()` states and CLI output labels them. Fifteen integration tests cover defaults, ALL/ANY behavior, action isolation, disabled ancestry, fail-fast, snapshot stability, CLI/Blade/permission integration, and migration/custom-table round trips.
- Context validation implemented (2026-10-31): optional `context_rules` keyed by exact group name, using Laravel validation rules. Invalid context skips that group’s expressions/descendants and records a `ValidationException` plus local `validationErrors()` messages. Parent errors aggregate child validation failures; actions cannot run from a failed selection. CLI/Blade/permission entry points share this behavior. Fourteen integration tests cover required/type/nested/wildcard/optional fields, unchanged context, exact keys, invalid configuration, and integration behavior.
- Rule testing command implemented (2026-10-31): `rulecraft:test <group> --data=<json>` displays nested decisions/errors without running actions. Exit codes are 0/pass, 1/denial, and 2/invalid input or evaluation errors. Nine integration tests cover passing/denied/empty groups, nested results, expression errors, missing groups/context strings, invalid JSON/root types, nested context, and action isolation. Remaining TODO features require separate user approval.
- Blade conditions `@rulecraft` and `@canRulecraft` use evaluation only, with guest denial and optional authentication/permission guard selection.
- PHP 7.3-compatible property PHPDoc, native parameter/return types, and generic array/collection annotations; PHPStan level 8 checks type safety; all package PHP files declare strict types.
- Native Laravel service provider and facade; namespace `Thuraaung\Rulecraft`.
- Eloquent groups and rules, nested all/any groups with mixed rules and child groups, isolated fail-fast options, child results, and flattened leaf diagnostics.
- Symfony expression evaluation, validation without executing business functions, and container-resolved function providers.
- Explicit `evaluate()` and `run()` behavior, action contracts, preflight handler registration checks, ordered execution, accumulated context, and preserved failure outcomes.
- Provider-based `Rulecraft::registerAction($name, $class)` and existing config registrations share an application-scoped registry. Duplicate names are rejected; handlers are container-resolved only during execution.
- Configurable names for all three tables, relationships, foreign keys, schema creation, rollback, and config caching. The single initial migration includes parent links, enabled states, indexes, and foreign keys; hierarchy guards reject cycles and excessive depth.
- Spatie Laravel Permission integration selected by the user. Actual grants are looked up by role, permission identity, and guard, with current team scoping. Each complete grant is evaluated independently; any passing grant authorizes. Direct permissions, wildcard expansion, and Gate bypasses do not bypass conditional checks.
- Configurable grant resolver for other integrations, one role-permission-group mapping per pair, morph identities and string keys, and an explicit guard selector.
- Documentation organized with progressive disclosure: the README contains the implemented feature list and getting started for every core workflow in one place: installation, rules/evaluation, nested groups, actions, conditional permissions, Blade, context validation, states, CLI testing, JSON transfer, and evaluation listeners. Optional detail pages cover API methods/results/errors, expression syntax, and every config key/default. A use-case index links to complete examples under docs/examples/, with setup and common outcomes kept together. Development remains a separate reference. Expression examples and the action/custom-function examples were previously checked against the implementation; the consolidated pages have checked links, anchors, and PHP snippet syntax.
- Project development instructions, formatting/static-analysis configuration, and CI for Laravel 7–13.

Earlier engine/builder verification (205-test suite):

| PHP | Laravel | Integration suite |
| --- | --- | --- |
| 7.3.33 | 7.30.7 | 205 tests passed |
| 7.3.33 | 8.83.29 | 205 tests passed |
| 7.4.33 | 7.30.7 | 205 tests passed |
| 7.4.33 | 8.x | 205 tests passed |
| 8.0.30 | 9.52.23 | 205 tests passed |
| 8.3.33 | 9.52.23 | 205 tests passed |
| 8.3.33 | 10.x | 205 tests passed |
| 8.3.33 | 11.x | 205 tests passed |
| 8.3.33 | 12.x | 205 tests passed |
| 8.3.33 | 13.39.0 | 205 tests passed |
| 8.5.12 | 13.x | 205 tests passed |

The 205-test suite covers the original functionality plus mixed/nested groups, the user's two loan examples, every listed product ID, subtree action eligibility, cycle/depth protection, subtree moves/deletion, legacy configuration fallback, initial schema creation/rollback/recreation, configurable hierarchy tables, and root-only permission mappings. Five Blade tests cover branch rendering, expression errors, optional context, missing groups, guest denial, explicit/default guards, revoked grants, and evaluation without action execution. Ten action-registration tests additionally cover provider boot, mixed config/provider execution, shared registration across service resolutions, lazy construction, cached-config boot, duplicate/invalid registrations, and handler resolution failures before execution. Local suites passed on all combinations listed above. Composer validation, syntax checks, Pint formatting, and Larastan level 6 passed. Additional expression-branch checks passed with Symfony 6.4.48 and 8.1.9; the 8.0 branch was blocked by Composer for advisory PKSA-vq1j-sc3k-jzzk, so the dependency now requires ^8.1 for Symfony 8. Historical compatibility tests use a command-scoped Composer security-blocking exception because old Laravel dependencies have known advisories; no global or published security setting was changed.

PHP 7.3/Laravel 7 and 8, plus PHP 8.0/Laravel 9, passed local container verification on 2026-11-02. Each separate dependency installation passed Composer validation, actual platform checks, syntax checks, and all 205 integration tests. No platform emulation or ignored platform requirements were used; installed PHP versions and Valet configuration were unchanged. See the [exact versions and reproduction commands](docs/development.md#minimum-runtime-verification). The separate Laravel 13/PHP 8.5 SQLite demo also passes its 43 tests. Other minimum-PHP combinations, MySQL/PostgreSQL execution, and lowest-dependency testing remain to be verified. No GitHub Actions run or publication has been performed.

Rulecraft Builder includes free context fields and provider-defined visual functions with typed arguments, server compilation, and a single metadata schema. Twenty additional integration tests cover these workflows, the unified metadata schema, and functions on either side of a comparison; custom and Bootstrap browser checks pass. The initial Rulecraft Builder is implemented. Automatic expression-to-visual conversion, revision history, and additional authorization integrations remain deferred. The implementation record and remaining CI verification are in [BUILDER_PLAN.md](BUILDER_PLAN.md).

## Per-rule failure messages (2026-11-02)

Implemented nullable `Rule::failure_message` in the consolidated initial migration and mass-assignment API. `RuleResult::message()` snapshots custom text or a rule-name default for ordinary false conditions; passing, disabled, and expression-error results return null. Messages are plain text, independent of technical errors, with no context interpolation. Builder Rule details, preview, save/load/revisions, both policy formats, and the testing command preserve/display them. Added 12 integration tests and both-theme browser workflows. The existing demo database was backed up and received the nullable column without losing its three rules.

Current 217-test suite verified on PHP 7.3/Laravel 7 and 8, PHP 7.4/Laravel 7, PHP 8.0/Laravel 9, and PHP 8.3/Laravel 9 and 13. Composer validation, syntax checks, Pint, and Larastan level 6 pass. Browser checks cover custom/default messages, clearing, literal HTML text, errors, and save/reload in both themes. No GitHub Actions run has been performed.

## Success messages and group summaries (2026-11-02)

Added nullable `Rule::success_message` in the consolidated initial schema. `RuleResult::message()` snapshots the actual outcome's text, keeping success silent when unconfigured and failure's rule-name fallback intact. Disabled/error rules remain silent. `EvaluationResult::failedMessage()` joins visited ordinary failures; `passedMessage()` joins only configured success messages on eligible passing paths and is empty for failed groups. Both return strings, default to a space separator, and accept a per-call separator. Builder preview includes rule messages and both group aggregates; storage, revisions, both transfer formats, CLI output, and documentation preserve the same semantics. The demo schema was backed up and updated with all three saved rules preserved.

The current 227-test suite passes on PHP 7.3/Laravel 7 and 8, PHP 7.4/Laravel 7, PHP 8.0/Laravel 9, and PHP 8.3/Laravel 9 and 13. Ten new integration tests cover aggregation, failed-branch exclusion, optional success text, snapshots, separators, zero text, fail-fast, disabled/error/context cases, CLI output, and message-only revision changes. Existing tests now also cover success metadata round-trips, invalid input, custom tables, and HTTP summaries. Both-theme browser workflows, Pint, and Larastan level 6 pass. No GitHub Actions run has been performed.

## Strict typing and PHPStan level 8 (2026-11-02)

Added `declare(strict_types=1);` to all 78 PHP source/configuration/migration/route/test/tool files, with enforcement through Pint's `declare_strict_types` rule. Raised the checked-in PHPStan configuration from level 6 to level 8. Fixed its unchecked JSON-encoding result in Builder preview by using throwing JSON conversion and a named intermediate value. Nonfinite input such as `1e309` now produces HTTP 422 instead of a type error; an existing HTTP regression test covers it. No type-error suppressions or analysis baselines were added.

PHPStan level 8, Pint, Composer validation, and syntax checks pass. All 227 tests pass on PHP 7.3/Laravel 7 and 8, PHP 7.4/Laravel 7, PHP 8.0/Laravel 9, and PHP 8.3/Laravel 9 and 13. Both Builder themes and the demo's nine Rulecraft integration tests pass. Local PHP and Valet configuration remain unchanged; no remote CI run has been performed.

## GitHub and Packagist delivery (2026-11-04)

Initialized Git on branch `latest`, providing Composer version `dev-latest`, and pushed the package to [thuraaung2493/laravel-rulecraft](https://github.com/thuraaung2493/laravel-rulecraft). Added Composer metadata, archive exclusions, and a CI-gated Packagist job with optional initial registration. Bearer authentication uses repository secrets; pull requests cannot publish. The publishing guide documents main-token registration and safe-token updates. The first [GitHub Actions run](https://github.com/thuraaung2493/laravel-rulecraft/actions/runs/41760728605) passed all eight PHP/Laravel combinations, PHPStan level 8, Pint, formatting, and Builder browser checks. Packagist is not registered yet; credentials remain to be configured.

Repository creation and push completed privately after automatic approval review required explicit approval for public source disclosure. Public visibility is pending the user's approval. No stable tag or hard-coded Composer version was added.

## Product direction

- Product name: **Rulecraft**.
- Positioning: a developer tool for defining and evaluating business rules.
- Laravel package name: `thuraaung2493/laravel-rulecraft` (planned; availability has not been checked).
- Rule editor: **Rulecraft Builder**, provided as an optional Blade component.
- Rulecraft Builder uses an optional, embeddable Blade UI with plain JavaScript and configurable custom/Bootstrap style presets. Detailed scope and contracts are in [BUILDER_PLAN.md](BUILDER_PLAN.md).

## Objective

Build a fresh package in the new project directory. No applications used the archived implementation, so legacy upgrade support is outside the scope.

Design and implement Rulecraft as a maintainable Laravel rule engine with clear evaluation behavior, extensible expressions and actions, and complete installation and usage documentation.

This initial plan is based on the existing package README, Composer configuration, and source/test inventory. Detailed redesign requirements and compatibility decisions remain to be established during discovery.

## Confirmed requirements

### Engineering principles and public API

Confirmed requirements:

- Follow Laravel conventions for package discovery, service providers, configuration, Eloquent, migrations, dependency injection, and exceptions, within the supported version range.
- Apply SOLID pragmatically: give each class a cohesive responsibility, keep extension contracts focused, and inject collaborators where substitution is useful. Create interfaces for real extension boundaries rather than automatically pairing every class with an interface.
- Follow SLAP (Single Level of Abstraction Principle): keep each method at a consistent abstraction level. High-level evaluation methods describe the evaluation steps; collaborators or focused methods own their implementation details.
- Use clear domain names, explicit outcomes, readable control flow, and small cohesive methods. Avoid arbitrary method-length limits or splitting trivial operations solely to increase the number of methods.
- Keep the implementation proportional to the current requirements. Every abstraction, dependency, and layer must solve a concrete problem; speculative future flexibility is insufficient justification.
- Use Eloquent directly for current database persistence. Introduce repositories or storage adapters only when a concrete requirement justifies them.
- Keep Rulecraft Builder's UI concerns outside the evaluation core. Keep its field/definition boundary focused without building a generic workflow or plugin framework.
- Keep the public API small, expressive, and familiar to Laravel developers. Prefer clear domain verbs, sensible defaults, and consistent argument and return conventions.
- Add fluent methods when optional configuration reads naturally; keep ordinary evaluation concise. Do not require consumers to construct internal option objects or know service-layer classes for a basic evaluation.
- Provide a facade backed by a container-resolvable service so applications can use either Laravel-style static access or dependency injection.
- Make return values and exception behavior explicit and consistent. Document whether an operation evaluates conditions or also executes actions; avoid hidden side effects.
- Review proposed APIs through realistic consumer examples before committing to method names and contracts. Preserve PHP 7.3 syntax in package code and examples.

Illustrative public API direction (not a finalized contract):

```php
$result = Rulecraft::group('discounts')->evaluate([
    'total' => 150,
]);

if ($result->passed()) {
    // Use the evaluation result.
}
```

Review criteria: a consumer can understand the basic call without learning internal architecture; classes and methods have clear responsibilities; extension points have an identified use case; new layers or dependencies have a documented concrete purpose.

### Runtime compatibility and expression engine

Confirmed choices:

- Minimum PHP version: **7.3**.
- Minimum Laravel version: **7**.
- Expression engine: **`symfony/expression-language`**, as a core dependency.
- Target Laravel **7 through the latest stable major**, currently **13** as of 2026-10-30. Each Laravel version must run on its own compatible PHP versions; Laravel 13 requires PHP 8.3 or later. PHP 7.3 remains the package source compatibility floor.
- Add future Laravel majors through explicit Composer constraints and passing compatibility tests before claiming support; do not use an unbounded Laravel version constraint.

Implementation constraints:

- Package source and published PHP files must parse and run on PHP 7.3. Do not use typed properties, arrow functions, union types, attributes, enums, constructor property promotion, or other syntax introduced after PHP 7.3.
- Use PHP 7.3-compatible classes for data objects and constants for fixed values, with PHPDoc where newer type syntax is unavailable.
- Use APIs supported by Laravel 7 or explicitly tested compatibility adapters.
- Symfony Expression Language 5.4 is the PHP 7.3 baseline candidate. Evaluate 6.4, 7.4, and 8.x for newer environments; allow multiple tested major versions so Composer can select versions compatible with the host application. Exact constraints remain subject to installation and behavior tests.
- Keep builder-generated expression syntax within the tested common subset of supported Symfony versions. Detect and report advanced expressions requiring capabilities unavailable on the installed engine.
- Preserve configurable table names when using Laravel 7-compatible models, relationships, and migrations.
- Define how Rulecraft Builder's editable structure maps to Symfony expressions, including behavior for expressions the visual editor cannot represent.
- Run real PHP 7.3/Laravel 7 integration tests and at least one compatible runtime for every Laravel major through 13, including PHP 8.3/Laravel 13. Exercise lowest and latest allowed dependencies where practical; Composer platform emulation alone does not demonstrate runtime compatibility.
- Use shared expression fixtures across the matrix to verify evaluation semantics, registered functions, and builder-generated expressions.

Proposed tooling, pending dependency resolution and tests:

- Native Laravel service provider for package bootstrapping, configuration merging/publishing, and migrations. Do not require the current Spatie Laravel Package Tools release, which raises the PHP/Laravel minimums.
- `orchestra/testbench` 5.x and `phpunit/phpunit` 9.6 for the PHP 7.3/Laravel 7 test job. Use compatible Testbench/PHPUnit versions in separate framework test jobs; Testbench 11.x is the Laravel 13 candidate. Tooling resolution and shared test compatibility must be verified.
- Select static analysis and formatting tooling that can validate PHP 7.3 syntax; tools may run separately on a newer PHP runtime without raising the consumer minimum.
- Builder frontend dependencies and delivery format remain undecided and must not raise the engine's runtime requirements.

Verified dependency references:

- [Symfony Expression Language 5.4 requirements](https://github.com/symfony/expression-language/blob/5.4/composer.json)
- [Laravel 7 requirements](https://github.com/laravel/framework/blob/7.x/composer.json)
- [Testbench 5.x requirements](https://github.com/orchestral/testbench/blob/5.x/composer.json)
- [PHPUnit 9.6 requirements](https://github.com/sebastianbergmann/phpunit/blob/9.6/composer.json)
- [Latest Laravel release and PHP support](https://laravel.com/framework/docs/releases)
- [Symfony Expression Language 7.4 requirements](https://github.com/symfony/expression-language/blob/7.4/composer.json)
- [Symfony Expression Language 8.0 requirements](https://github.com/symfony/expression-language/blob/8.0/composer.json)
- [Testbench 11.x requirements](https://github.com/orchestral/testbench/blob/11.x/composer.json)

### Nested condition groups

Implemented requirement: a group contains direct rules, child groups, or both. Each group independently combines its immediate results using `all` (AND) or `any` (OR).

Examples:

- `same branch AND (within limit OR special approval)` is an `all` root with a branch rule and an `any` child containing the other two rules.
- The original zone/risk/region/product expression is an `any` root with three child groups. The first two children use `all` and store each comparison as an individual rule; the last contains the allowed-product membership rule.

Storage and API:

- Nullable `parent_id` in the existing configurable group table forms a single-parent tree. `parent()` and `groups()` are native Eloquent relationships; group names stay globally unique.
- The single initial migration creates the nullable parent column, its index, and the self-referencing cascade constraint along with enabled states and the other tables.
- Native Laravel schema creation defines the parent foreign key, including on Laravel 7/SQLite. Rollback drops the package tables in dependency order; recreation starts with empty tables. There is no added-column rollback or SQLite 3.35 requirement from these migrations. MySQL/PostgreSQL execution remains pending.
- `Rulecraft::group($name)->evaluate($data)` and `run($data)` retain their public call shape. Direct evaluation of a child selects that subtree; it does not inherit its ancestors' conditions.
- Result `rules()` stays local to the selected group; `groups()` contains immediate child results keyed by name; `allRules()` flattens evaluated leaves. Matching, failure, and error diagnostics include descendants.
- `eligibleRules()` includes only matching leaves with a fully passing path to the selected root. Actions use this list, so failed branches never execute handlers even when a sibling branch passes.

Behavior and protections:

- All nodes receive the same evaluation input. Empty groups fail, and expression errors in any evaluated descendant fail the selected root.
- Traversal evaluates direct rules by descending priority then ID, then child groups by ID recursively. Eligible actions follow that traversal after root success.
- Fail-fast on an `all` root also short-circuits nested `all` groups; nested `any` groups still evaluate all children. Skipped nodes do not appear in results.
- The configurable depth limit defaults to 10, counting the root. A shared constant supplies the default even for older cached configuration without the new key. Explicit settings must be positive integers.
- Model saves validate ancestors and the full moved subtree, rejecting missing parents, cycles, and excessive depth. Runtime checks also reject invalid trees after raw database writes or a reduced limit.
- Conditional permission mappings must reference roots. Reparenting an assigned root requires removing its mappings first. Permission evaluation rejects child mappings and records hierarchy failures without borrowing another role's conditions.

Verification: 22 additional integration tests cover these behaviors, bringing the suite to 56 tests. Composer validation, PHP syntax checks, Pint, and Larastan level 6 passed. See `README.md` and `docs/examples.md` for installation and usage details.

### Conditional permissions by role

Agreed relationship: **Role + Permission -> Condition Group**. A condition group belongs to a particular role-permission assignment, so the same permission can have different conditions for different roles.

Example:

| Role | Permission | Condition group |
| --- | --- | --- |
| Branch Manager | Loan Approval | Branch approval conditions |
| Regional Manager | Loan Approval | Regional approval conditions |

The Branch Manager group could contain these conditions:

```text
loan_amount <= 500000
loan_branch_id == user_branch_id
credit_score >= 650
```

Evaluation behavior:

1. Resolve the supplied user's actual role-permission assignments from the application's authorization data.
2. Select only roles that actually grant the requested permission.
3. Find the condition group mapped to each matching role-permission assignment.
4. Evaluate each eligible assignment's own group with application-supplied context; permission checking does not execute action handlers.
5. Allow when at least one actual grant has a passing associated condition group. If none does, deny.

Keep each role's grant and conditions together. Never combine one role's permission with a different role's group. A missing group or evaluation error cannot count as a passing grant.

Agreed public API direction (result details and integration mechanics remain to be finalized):

```php
$result = Rulecraft::for($user)
    ->permission('loan-approval')
    ->evaluate([
        'loan_amount' => $loan->amount,
        'loan_branch_id' => $loan->branch_id,
        'user_branch_id' => $user->branch_id,
        'credit_score' => $loan->credit_score,
    ]);

$result->passed();
```

Persistence direction: add one package-owned mapping table linking role, permission, and condition group. Its proposed configuration key is `tables.role_permission_rules`, with default name `rulecraft_role_permission_rules`. Keep existing role-permission tables unchanged. Final keys, uniqueness constraints, provider integration, and any guard/tenant scoping must be designed around the chosen authorization integration.

Acceptance: cover a passing and failing Branch Manager grant, different groups for the same permission across roles, permission not granted, no matching assignment, missing groups, evaluation errors, and multiple roles where exactly one complete grant passes. Verify that permission checking never executes actions and that the mapping table honors a custom configured name.

### Configurable database table names

Every package-owned database table must have its own entry under `tables` in the publishable `config/rulecraft.php` file. Consumers must be able to choose arbitrary table names independently, rather than being restricted to a shared prefix.

Proposed initial configuration (the final schema may introduce additional table keys):

```php
return [
    'tables' => [
        'rules' => 'rulecraft_rules',
        'rule_groups' => 'rulecraft_rule_groups',
        'role_permission_rules' => 'rulecraft_role_permission_rules',
    ],
];
```

Requirements:

- Models, relationships, joins, repositories, validation queries, and migrations must consistently resolve table names from `rulecraft.tables`.
- Any pivot tables or other tables introduced later must also have individual configuration entries; their names must never be inferred from model names.
- Migration creation, foreign-key references, and rollback must use the configured names, including references between tables with independently customized names.
- Default names live in the package configuration; application code must not contain duplicate hardcoded table names.
- Normal Laravel configuration caching must preserve the selected table names.
- Configure names before running migrations. Changing configuration alone does not rename existing tables; later changes require an explicit data-preserving migration. Rollback must use the names applicable to the schema being rolled back.

Acceptance: an integration test overrides every package table name, creates the schema, persists and evaluates related rules, and rolls the schema back successfully. Default-named package tables must not be created or queried during that test.

## Rulecraft Builder plan

The dedicated [Rulecraft Builder plan](BUILDER_PLAN.md) records the Blade component usage, style presets, custom CSS and view overrides, field catalog, expression preservation, preview, saving, authorization, and phased acceptance criteria. It records the agreed UI direction and implemented contracts. Implementation was authorized and completed locally on 2026-11-01; remaining runtime CI checks are listed there.

## Current baseline

The previous package documents database-backed rules and rule groups, Symfony Expression Language expressions, fail-fast and evaluate-all modes, custom action handlers, expression providers, and structured evaluation results. It contains Pest tests and Composer scripts for tests, static analysis, and formatting.

## 1. Establish requirements and compatibility

- [x] Review the archived previous implementation and tests; record current behavior and known problems.
- [x] Define representative use cases and expected evaluation results.
- [x] Decide which public APIs, stored rules, and database structures must remain compatible.
- [x] Set the minimum runtime versions to PHP 7.3 and Laravel 7; choose Symfony Expression Language.
- [x] Set the Laravel support target to every major from 7 through the latest stable release (currently 13).
- [ ] Define the full supported runtime matrix and verify dependency constraints for each combination.
- [ ] Record decisions and acceptance criteria before implementation.

Deliverable: requirements, behavior examples, and a compatibility policy.

## 2. Define the architecture and public API

- [x] Design the smallest set of collaborators needed for evaluation and actions; introduce contracts only for concrete extension or substitution needs. Use Eloquent directly for the initial persistence implementation.
- [x] Define a serializable builder representation, validation rules, versioning, and translation to Symfony expressions that can support Rulecraft Builder.
- [x] Specify evaluation inputs and result objects for selected groups and nested child groups.
- [x] Design conditional-permission lookup for actual role-permission assignments and the agreed any-passing-grant behavior, keeping the integration small.
- [x] Specify rule ordering, short-circuit behavior, empty groups, missing groups, and invalid expressions.
- [x] Specify when actions execute and how action failures affect evaluation results.
- [x] Define registration of custom handlers and expression providers through Laravel's container.
- [x] Review concise Laravel-style public API examples before implementation, including defaults, return values, exceptions, and action side effects.
- [x] Document the agreed API with executable usage examples.

Deliverable: architecture decisions and a proposed public API.

## 3. Implement the evaluation core

- [x] Create the package structure and Composer configuration using the agreed compatibility policy.
- [x] Implement rule and group evaluation with explicit outcomes and predictable ordering.
- [x] Implement fail-fast and evaluate-all behavior.
- [x] Distinguish failed conditions from evaluation exceptions.
- [x] Integrate Symfony Expression Language for evaluation and validation; define allowed functions and context access.
- [x] Add focused tests for normal behavior, boundary cases, and failures.

Deliverable: a tested evaluation core.

## 4. Integrate Laravel persistence and actions

- [x] Implement models, relationships, configuration, and migrations as required by the design.
- [x] Implement individual table-name configuration for every package-owned table and use it throughout persistence.
- [x] Verify default names and independently customized names, including relationships, foreign keys, rollback, and cached configuration.
- [x] Register services, facade bindings, handlers, and expression providers.
- [x] Implement action dispatch and structured action outcomes.
- [x] Implement the configurable role-permission-to-group mapping and conditional permission evaluation without modifying authorization-provider tables.
- [x] Test multiple-role grant isolation, denied and invalid mappings, and permission checks without action execution.
- [x] Test database-backed evaluation, package discovery, custom extensions, and action failures.

Deliverable: an installable Laravel package with integration coverage.

## 5. Document and validate the package

- [x] Document installation, configuration, rule creation, evaluation, and extension points.
- [x] Document all table-name configuration keys, setup before migration, and the procedure for changing names after installation.
- [x] Document failure handling, expression constraints, and action execution behavior.
- [x] Run the test suite, static analysis, formatting checks, and Composer validation.
- [x] Exercise installation and representative usage in a sample Laravel application.
- [x] Configure CI for the selected PHP and Laravel combinations.

Deliverable: documented, validated package source ready for release review.

## Completion criteria

- Agreed use cases pass automated tests with documented outcomes.
- Supported runtime combinations pass CI for every Laravel major from 7 through the latest declared supported release, including PHP 7.3/Laravel 7 and PHP 8.3/Laravel 13.
- Consumers can register custom expressions and actions through documented contracts.
- Package documentation matches the implemented API.
- The public API is concise and Laravel-style; implementation review verifies SOLID, SLAP, clear naming, and that abstractions and dependencies solve concrete requirements.
- Every package-owned table can be independently renamed through configuration; schema creation, evaluation, relationships, and rollback work with custom names.
- Conditional permission checks resolve actual role-permission grants and allow only when at least one complete grant has a passing mapped condition group.

## Decisions to resolve

- Confirm availability of `thuraaung2493/laravel-rulecraft` and choose the PHP namespace.
- Release scope resolved: fresh package and schema with no legacy consumers; no legacy upgrade guide, compatibility adapter, or data migration tooling is required. Installation still uses Laravel database migrations.
- Persistence resolved: Eloquent/database-backed rules for the initial release; optional storage adapters require a concrete future use case.
- Action timing resolved: `evaluate()` executes no action handlers and emits a completion event; `run()` synchronously executes matched-rule actions only after group success.
- Performance targets and any caching requirements.
- Authorization integration resolved for initial release: optional Spatie adapter, exact actual role grants, morph model identities, explicit/default guard, fresh scoped team membership, no direct-user or Gate bypass, and per-mapping result diagnostics. Additional providers and wildcard expansion remain future work.
- Exact runtime test matrix, Symfony version constraints, and tooling for newer framework test jobs.
- Builder representation and packaging are implemented; see [BUILDER_PLAN.md](BUILDER_PLAN.md). Automatic reverse conversion and persistent revisions remain deferred.

## Initial setup

- [x] Draft this Markdown plan.
- [x] Create the project directory and place this plan in it.
- [x] Archive the old package in the Trash and use `laravel-rule-engine` for the fresh project.
- [x] Choose Rulecraft as the product name and rename the project directory to `laravel-rulecraft`.
- [x] Begin requirements discovery.
