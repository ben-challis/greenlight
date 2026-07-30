# Greenlight adapter for Infection

This package is the [Infection](https://infection.github.io/) adapter for
Greenlight.

```sh
composer require --dev infection/infection greenlight/infection-adapter
vendor/bin/infection --test-framework=greenlight
```

The adapter records per-test coverage during Infection's initial test run and
converts it to Infection's XML format. It then runs each mutant against the
Greenlight tests that covered the changed line.

Greenlight still reads `greenlight.php`. The adapter uses Infection's source
directories as coverage include paths. Run the full Greenlight suite separately
in CI.
