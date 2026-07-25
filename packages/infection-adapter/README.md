# Greenlight adapter for Infection

This package lets [Infection](https://infection.github.io/) use Greenlight as
its test framework.

```bash
composer require --dev infection/infection greenlight/infection-adapter
vendor/bin/infection --test-framework=greenlight
```

The initial run enables Greenlight's opt-in per-test coverage map and converts
it to Infection's current XML input. Mutant runs receive only the exact
Greenlight test IDs that covered the mutated line. Greenlight's normal
configuration remains in `greenlight.php`; Infection's source directories
become the coverage include paths.

Per-test coverage is intentionally used only for Infection's mutation-test
selection. It does not replace a normal full-suite CI run.
