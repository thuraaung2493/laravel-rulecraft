# Publishing Rulecraft

[Back to README](../README.md) · [Development checks](development.md)

The repository is `thuraaung2493/laravel-rulecraft`. The first beta is `v0.1.0-beta.1`. The default development branch is `main`, so Composer also exposes `dev-main`. Do not put a `version` field in `composer.json`: Packagist reads versions from Git branches and tags. See [Composer versions](https://getcomposer.org/doc/articles/versions.md).

## CI and publishing

The Tests workflow runs on pushes, pull requests, and manual dispatch. It checks all configured PHP/Laravel combinations, Composer metadata/runtime requirements, syntax, PHPStan level 8, Pint, Builder formatting, and browser behavior. Packagist runs only after all three check jobs succeed, for the `main` branch or tags starting with `v`, outside pull requests. Older runs for the same ref are canceled when a new run starts.

Packagist receives the repository URL and indexes the Git source; this workflow uploads no package archive. GitHub distribution archives exclude tests, development tools, and CI files through `.gitattributes`. Runtime code, configuration, migrations, views, bundled assets, documentation, and the license remain included.

## First-time setup

1. Create or use your Packagist account.
2. In GitHub repository **Settings → Secrets and variables → Actions**, add `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` as repository secrets. Never commit tokens.
3. For first-time registration through Actions, use the **main** Packagist API token. In **Actions → Tests → Run workflow**, select branch `main` and enable **Register this repository on Packagist for the first time**. The package is registered only after every check passes.
4. After registration, replace `PACKAGIST_TOKEN` with the **safe** API token. Leave the registration option disabled for subsequent runs.

Alternatively, submit `https://github.com/thuraaung2493/laravel-rulecraft` through the [Packagist submission form](https://packagist.org/packages/submit), then configure the username and safe token for updates. The [Packagist API documentation](https://packagist.org/apidoc#authentication) describes the two token types and bearer authentication.

Missing secrets produce an explicit notice and workflow summary; tests still run, and publishing is skipped. API/authentication failures fail the publishing job. Credentials are sent in the Authorization header, never in the URL. Pull requests cannot publish.

## Updating the development version

Push reviewed changes to `main`. When all CI checks pass, Actions requests a Packagist refresh. No new tag is needed for `dev-main`. To retry publishing without another commit, run the Tests workflow manually on `main` with the registration option disabled.

Once Packagist has indexed the branch, consumers install:

```bash
composer require thuraaung2493/laravel-rulecraft:dev-main
```

After a push to `main` passes CI and Packagist indexes the commit, users requiring `dev-main` update with:

```bash
composer update thuraaung2493/laravel-rulecraft
```

Check the workflow's publishing summary and the [Packagist package page](https://packagist.org/packages/thuraaung2493/laravel-rulecraft) to confirm indexing. A successful Git push or CI run without configured publishing secrets does not mean the package is on Packagist.

## Beta releases

Create a version tag on a reviewed commit after its checks pass, then publish a GitHub release marked as a prerelease. For the first beta:

```bash
git tag -a v0.1.0-beta.1 -m "Release v0.1.0-beta.1"
git push origin v0.1.0-beta.1
```

The tag starts the full Tests workflow. Once checks pass, the publishing job requests a Packagist refresh using the configured repository secrets. Verify the tag appears on Packagist before announcing availability. A GitHub prerelease alone does not request that refresh.

Consumers explicitly opt into the beta:

```bash
composer require thuraaung2493/laravel-rulecraft:0.1.0-beta.1
```

Beta releases may change before the stable release. The `dev-main` installation remains available for ongoing development.
