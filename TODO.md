# Rulecraft feature TODO

Feature checklist in implementation order. Checked items are complete; unchecked items remain planned. See [PLAN.md](PLAN.md) for current implementation status. Proceed to the next feature only after user approval.

## 1. Rule testing command

- [x] Implement a command such as `php artisan rulecraft:test <group> --data='{"amount":100}'`.
- [x] Accept a group name and JSON context string.
- [x] Display nested pass/fail results and expression errors.
- [x] Return meaningful exit codes for passing, failing, and invalid evaluations.
- [x] Evaluate conditions only; never execute actions.

Completed 2026-10-31. Added nine command integration tests and updated the CLI examples and API reference.

## 2. Context validation

- [x] Define required fields and expected types.
- [x] Support nested input.
- [x] Report validation failures clearly.
- [x] Keep validation optional for existing policies.

Completed 2026-10-31. Optional per-group Laravel validation rules cover nested fields, preserve original data, and report field errors through evaluations, CLI, Blade, and permissions. Added 14 integration tests and updated task guides/API reference.

## 3. Enable/disable policies

- [x] Add configurable states for groups and rules.
- [x] Define disabled-node behavior for ALL/ANY groups, including a disabled selected root and groups with no enabled children.
- [x] Ensure disabling conditions cannot accidentally allow access.

Completed 2026-10-31. Added enabled flags in the initial schema using configured tables, disabled result snapshots/CLI labels, and 15 integration tests. Disabled nodes count as false; disabled groups and ancestors block subtree execution. Updated task guides and API reference.

## 4. Policy import/export

- [x] Export complete group trees as versioned JSON.
- [x] Validate imports before saving.
- [x] Define handling for duplicate names.
- [x] Preserve action names, parameters, and priorities.
- [x] Export action names only, never handler code.
- [x] Support JSON strings and local files through PHP/Artisan APIs, with pure JSON stdout for clipboard piping.

Completed 2026-10-31. Strict version 1 JSON preserves complete trees, states, ordering, action names, and parameter shapes. Imports validate before writes and reject duplicate group names without overwriting. Added 28 integration tests for transfer, commands, custom tables, and rollback; updated the task guide and API reference.

## 5. Evaluation events

- [x] Dispatch events for completed group and permission evaluations.
- [x] Include results for application listeners.
- [x] Leave audit storage and metrics to the application.

Completed 2026-10-31. Added GroupEvaluated and PermissionEvaluated with completed results, original context, and selection metadata. Group events fire once per public selection; permission checks emit completed grant-group events before their final event. Twenty integration tests cover timing, payloads, errors, disabled/fail-fast results, actions, permission grants, and CLI/Blade behavior. Updated the README, API reference, configuration, and complete listener examples. Auditing and metrics storage remain application-owned.

## 6. Rulecraft Builder

Detailed scope, component/configuration APIs, styling, and implementation phases: [Rulecraft Builder plan](BUILDER_PLAN.md). Initial release implemented locally.

- [x] Build nested ALL/ANY groups visually.
- [x] Provide field and operator selection.
- [x] Validate and preview conditions without executing actions.
- [x] Preserve expressions the visual editor cannot represent, with an expression-editing fallback.

Completed locally 2026-11-01. Added the embeddable Blade component, custom/scoped Bootstrap presets, CSS overrides and publishable views, visual/code editing, typed operands, protected endpoints, draft preview without policy writes/actions/events, transactional identity-preserving saves, revision conflict detection, and version 2 visual metadata transfer with version 1 support. Added 28 integration tests plus browser checks. PHP 7.3/Laravel 9 runtime verification completed locally on 2026-11-02; see below.

Builder extension completed 2026-11-01: optional `BuilderExpressionProvider` metadata exposes registered functions as typed visual controls. Context fields work without a catalog. Added 12 integration tests and expanded browser checks for functions and free fields; all 205 package tests pass across seven local setups.

Builder metadata now uses one source/operator/operand schema without version flags or compatibility branches. Stored field types keep visual editing available after removing the optional catalog. The demo seed and development records use this same schema.

Comparison functions completed 2026-11-02: **Compare with → Custom function** supports field-to-function and function-to-function comparisons, independent typed argument bindings, compatible return types, and scalar-operator changes without losing arguments. Added seven integration tests and browser coverage in both themes. All 205 package tests pass across seven local setups.

Builder UI simplified 2026-11-02: conditions use three full-width Source/Operator/Compare with rows, with kind and value controls together. Optional panels preserve disclosure state during redraws. New conditions receive default names. Operator labels include their symbols, such as Equals (==). Browser workflows cover disclosure, automatic names, both themes, and mobile layouts.

Rule details layout updated 2026-11-02: Name, Priority, and Editor are always visible first. Field type is grouped with Source and literal type with Compare. Action and Generated expression have independent optional panels; generic Rule settings is removed.

Function selectors simplified 2026-11-02: Source and Compare show read-only typed signatures such as `credit_score(int applicant_id): int`. New selections bind arguments to matching context keys automatically; separate function type and argument controls are removed. Loaded definitions preserve their actual bindings. The demo uses `applicant_id`; browser workflows cover matching/missing keys, boolean/numeric and zero/multiple-argument functions, save/reload, both themes, and mobile layouts.

## Compatibility verification

- [x] Verify PHP 7.3.33 with Laravel 7.30.7: 227 tests passed.
- [x] Verify PHP 7.3.33 with Laravel 8.83.29: 227 tests passed.
- [x] Verify PHP 8.0.30 with Laravel 9.52.23: 227 tests passed.

Completed 2026-11-02 using isolated containers and separate dependencies. Composer validation, real platform requirements, and PHP syntax checks passed in each environment. Added `composer check-platform-reqs` to the CI matrix. Local PHP/Valet configuration was unchanged. See [verification details](docs/development.md#minimum-runtime-verification). GitHub Actions itself has not run.

## 7. Per-rule failure messages

- [x] Store optional custom `failure_message` text in the consolidated initial schema.
- [x] Expose snapshot `RuleResult::message()` with a rule-name default for ordinary failures.
- [x] Keep passing/disabled/error results and technical exceptions distinct.
- [x] Edit and preview messages in both Builder themes; preserve save/load/revision behavior.
- [x] Preserve messages in both JSON formats and show them in the testing command.
- [x] Document API usage, fallback behavior, and complete examples.

Implemented 2026-11-02. Added 12 integration tests and browser coverage for custom/default messages, clearing, text escaping, save/reload, and error separation. Verification results are recorded in [PLAN.md](PLAN.md).

## 8. Success messages and group summaries

- [x] Store optional `success_message` text and snapshot the actual rule outcome message.
- [x] Add string helpers `failedMessage()` and `passedMessage()` with a space default separator.
- [x] Aggregate nested failures and success text from eligible passing branches only.
- [x] Preserve silent success defaults, disabled/error separation, and fail-fast traversal.
- [x] Edit/preview/save/clear both messages in Builder and preserve both JSON formats.
- [x] Document helpers, custom separators, branch semantics, and examples.

Completed 2026-11-02. Added 10 integration tests and expanded transfer, custom-schema, HTTP, and both-theme browser checks. All 227 tests pass on the six runtimes recorded in [PLAN.md](PLAN.md). Static analysis and formatting pass; the demo schema was backed up and updated without losing records.

## 9. Strict typing and PHPStan level 8

- [x] Add strict declarations to all package PHP files and enforce them with Pint.
- [x] Raise PHPStan to level 8 and fix reported issues without suppressions.
- [x] Reject unencodable Builder preview context with HTTP 422 and regression coverage.
- [x] Verify PHP 7.3 compatibility, modern runtimes, Builder workflows, and documentation.

Completed 2026-11-02. All 227 tests pass on six local runtime combinations; PHPStan level 8 and Pint pass. See [development](docs/development.md) for tooling and [PLAN.md](PLAN.md) for the implementation record.

## 10. Collapsible Builder messages

- [x] Move failure/success text from Rule details into a Messages panel like Action.
- [x] Show Defaults or a customized-count summary and preserve independent disclosure state.
- [x] Verify editing, redraws, preview, clearing, save/reload, and both themes.

Completed 2026-11-04. Message values and result APIs are unchanged; the documentation and browser workflows follow the new panel layout.

## 11. GitHub and Packagist delivery

- [x] Prepare CI-gated Packagist registration/update with credentials in repository secrets.
- [x] Use the `latest` development branch for Composer `dev-latest`, without a hard-coded version.
- [x] Add package metadata, distribution exclusions, and publishing instructions.
- [ ] Push the initial GitHub repository and verify remote CI.
- [ ] Configure Packagist credentials and complete initial registration.

Prepared 2026-11-04. Publishing is gated on all checks and never runs from pull requests. Missing secrets produce an explicit notice; registration uses a main token, and regular updates can use a safe token.

## Completion checklist for each feature

Apply this checklist separately to each feature:

- [ ] Keep the API compatible with supported PHP/Laravel versions.
- [ ] Follow Laravel conventions, SOLID pragmatically, SLAP, and clean code without speculative abstractions.
- [ ] Add behavioral tests appropriate to the feature.
- [ ] Update the relevant task guide and API reference.
