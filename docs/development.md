# Development and compatibility

[Back to README](../README.md) · [Publishing](publishing.md)

Use a Git checkout for maintainer checks; Composer distribution archives omit development tools and tests.

```bash
composer update
composer validate --strict
composer lint
composer test
```

To select a Laravel major, constrain the matching Testbench major during update, for example `composer update --with 'orchestra/testbench:^11.0'` for Laravel 13. CI is configured for Laravel 7–13 on compatible PHP runtimes and checks actual runtime requirements with `composer check-platform-reqs` before linting and testing. Historical framework releases have known dependency advisories and Composer may block their installation. The CI's historical compatibility jobs use a command-scoped `--no-security-blocking` exception inside isolated test environments; the package does not disable consumer or global security settings.

The separate PHP 8.3 quality job installs Larastan 3 and Pint 1, then runs `vendor/bin/phpstan analyse` and `vendor/bin/pint --test`. These modern tools are not required on the PHP 7.3 development baseline. The checked-in static analysis configuration uses level 8; syntax checks and the compatibility tests additionally run on their target PHP versions. PHP source, configuration, migrations, routes, tests, and tooling declare `strict_types=1`; Pint enforces the declaration. Existing native types and PHPDoc remain compatible with PHP 7.3. Strict PHP calls do not cast expression context values or replace optional context validation.

See [PLAN.md](https://github.com/thuraaung2493/laravel-rulecraft/blob/latest/PLAN.md) for exact local runtime verification and pending compatibility work.

## Minimum-runtime verification

Verified locally on 2026-11-02 in disposable Docker containers using the actual PHP runtimes:

| PHP | Laravel | Testbench | Integration suite |
| --- | --- | --- | --- |
| 7.3.33 | 7.30.7 | 5.20.0 | 227 tests, 1,415 assertions passed |
| 7.3.33 | 8.83.29 | 6.48.0 | 227 tests, 1,416 assertions passed |
| 8.0.30 | 9.52.23 | 7.58.0 | 227 tests, 1,414 assertions passed |

Each environment passed Composer validation, dependency installation, `composer check-platform-reqs`, PHP syntax checks, and the complete integration suite, including the builder, custom expression providers, and success/failure messages with group summaries. These runs used Symfony Expression Language 5.4.54 and PHPUnit 9.6.38. The PHP 7.3 environments used Spatie Permission 5.11.1; Laravel 9 used 6.25.0. Laravel 9 also passed all 227 tests on PHP 8.3.33 with Symfony Expression Language 7.4.21.

The containers used the official `php:7.3.33-cli-buster` and `php:8.0.30-cli-bullseye` images, with `unzip` added for Composer. Every combination had a separate package copy, dependency directory, and lock file. No Composer platform emulation or ignored platform requirements were used. The installed PHP versions, Valet configuration, and development package dependencies were unchanged.

To repeat a historical check, use an isolated package copy and its actual target PHP executable. For Laravel 9 on PHP 8.0:

```bash
php composer.phar validate --strict
php composer.phar update --with 'orchestra/testbench:^7.0' \
    --no-security-blocking --prefer-dist --no-interaction --no-progress
php composer.phar check-platform-reqs
php composer.phar lint
php composer.phar test
```

For PHP 7.3, select Testbench `^5.0` for Laravel 7 or `^6.0` for Laravel 8. `check-platform-reqs` checks the real PHP runtime and extensions, including when Composer platform settings exist; see the [Composer command reference](https://getcomposer.org/doc/03-cli.md#check-platform-reqs). These local results do not represent a GitHub Actions run; remote CI remains unverified.

## Builder browser checks

Maintainers can install the pinned development tools with `npm ci`; consuming apps use bundled assets and need no Node build. Google Chrome is used by default. CI installs bundled Chromium and selects it with `RULECRAFT_BROWSER_CHANNEL=chromium`.

Start the isolated, auto-authorized local browser fixture:

```bash
php -S 127.0.0.1:8927 tests/browser/router.php
```

In another terminal:

```bash
npm run test:browser
npm run format:check
```

The browser fixture uses a temporary SQLite database and session directory under the system temporary folder. Keep this fixture local; it is not an application route. Checks cover nested field comparisons, preview/save/reload, raw expressions, JSON parameter types, CSRF, stale edits, multiple components, scoped Bootstrap, application CSS overrides, keyboard focus, mobile layout, and escaping.

`RULECRAFT_BROWSER_URL` selects a different local test-server origin; `RULECRAFT_BROWSER_CHANNEL` selects a Playwright browser channel. Screenshots are written to the system temporary folder. To rebuild the scoped Bootstrap preset, download the official Bootstrap 5.3.8 CSS and run `npm run build:bootstrap -- /path/to/bootstrap.css`; retain its bundled MIT license. The preprocessing tool scopes selectors using PostCSS; no Bootstrap JavaScript is required by the editor.
