# Publishing Rulecraft

[Back to README](../README.md) · [Development checks](development.md)

The repository is `thuraaung2493/laravel-rulecraft`. The default development branch is `latest`, so Composer exposes `dev-latest`. Do not put a `version` field in `composer.json` or create a stable tag for this development release. Packagist reads versions from Git branches and tags. See [Composer branch versions](https://getcomposer.org/doc/articles/versions.md#branches).

## CI and publishing

The Tests workflow runs on pushes, pull requests, and manual dispatch. It checks all configured PHP/Laravel combinations, Composer metadata/runtime requirements, syntax, PHPStan level 8, Pint, Builder formatting, and browser behavior. Packagist runs only after all three check jobs succeed, and only for the `latest` branch outside pull requests. Older runs for the same ref are canceled when a new run starts.

Packagist receives the repository URL and indexes the Git source; this workflow uploads no package archive. GitHub distribution archives exclude tests, development tools, CI files, and planning documents through `.gitattributes`. Runtime code, configuration, migrations, views, bundled assets, documentation, and the license remain included.

## First-time setup

1. Create or use your Packagist account.
2. In GitHub repository **Settings → Secrets and variables → Actions**, add `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` as repository secrets. Never commit tokens.
3. For first-time registration through Actions, use the **main** Packagist API token. In **Actions → Tests → Run workflow**, select branch `latest` and enable **Register this repository on Packagist for the first time**. The package is registered only after every check passes.
4. After registration, replace `PACKAGIST_TOKEN` with the **safe** API token. Leave the registration option disabled for subsequent runs.

Alternatively, submit `https://github.com/thuraaung2493/laravel-rulecraft` through the [Packagist submission form](https://packagist.org/packages/submit), then configure the username and safe token for updates. The [Packagist API documentation](https://packagist.org/apidoc#authentication) describes the two token types and bearer authentication.

Missing secrets produce an explicit notice and workflow summary; tests still run, and publishing is skipped. API/authentication failures fail the publishing job. Credentials are sent in the Authorization header, never in the URL. Pull requests cannot publish.

## Updating the development version

Push reviewed changes to `latest`. When all CI checks pass, Actions requests a Packagist refresh. No new tag is needed for `dev-latest`. To retry publishing without another commit, run the Tests workflow manually on `latest` with the registration option disabled.

Once Packagist has indexed the branch, consumers install:

```bash
composer require thuraaung2493/laravel-rulecraft:dev-latest
```

They update it with:

```bash
composer update thuraaung2493/laravel-rulecraft
```

Check the workflow's publishing summary and the [Packagist package page](https://packagist.org/packages/thuraaung2493/laravel-rulecraft) to confirm indexing. A successful Git push or CI run without configured publishing secrets does not mean the package is on Packagist.
