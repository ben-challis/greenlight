# Greenlight adapter for Infection

This package connects [Infection](https://infection.github.io/) to Greenlight.

Install both development tools:

```sh
composer require --dev infection/infection greenlight/infection-adapter
```

Then select the adapter:

```sh
vendor/bin/infection --test-framework=greenlight
```

The adapter records per-test coverage during the initial Greenlight run. It
then runs only the tests that covered each mutant. Greenlight continues to read
`greenlight.php`. Infection source directories become coverage include paths.

See the [Infection support decision](../../docs/architecture/mutation-testing.md)
for lifecycle, compatibility, and cost details.
