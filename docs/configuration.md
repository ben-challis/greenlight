# Configuration

All configuration lives in one file: `greenlight.php` at the project root, returning a `Greenlight\Config\GreenlightConfig` builder. The CLI loads it, applies command-line overrides, and freezes the result into an immutable configuration object.

## Precedence

Three layers, applied in order and never conditionally:

1. Built-in defaults.
2. The config file.
3. CLI flags, which override the config file.

For example, `workers('auto')` in the file plus `--workers=1` on the command line runs with one worker.

## GreenlightConfig

Create the builder with `GreenlightConfig::create()`. Every method returns `$this`, so calls chain.

### paths(array $tests): self

Default: `['tests']`. The directories to discover tests in when no suite is selected. Paths must be non-empty strings and the list must not be empty.

### suite(string $name, callable $configurator): self

Default: no suites. Declares a named suite. The configurator receives a `SuiteBuilder` and must give the suite at least one path; its return value is ignored, so arrow functions work. Declaring the same suite name twice is an error.

```php
->suite('unit', fn ($s) => $s->in('tests/Unit'))
->suite('integration', fn ($s) => $s->in('tests/Integration')->tag('io'))
```

`SuiteBuilder` has two methods:

- `in(string ...$paths): self` adds directories to the suite. Required.
- `tag(string ...$tags): self` attaches tags to the suite. Optional.

### workers(int|string $count = 'auto', int $recycleAfterTests = 500, string $recycleAboveMemory = '256M'): self

Default: `'auto'` workers, recycling after 500 tests or above 256M. `$count` is a positive integer or the string `'auto'` (one worker per CPU core; install the suggested `fidry/cpu-core-counter` for detection that respects cgroup limits in containers). A worker is recycled once it has executed `$recycleAfterTests` tests or its memory use exceeds `$recycleAboveMemory` (a size string such as `'256M'` or `'1G'`).

### coverage(callable $configurator): self

Default: coverage off. The configurator receives a `CoverageBuilder`; calling `coverage()` at all enables collection. Calling it again reuses the same builder, so settings accumulate.

`CoverageBuilder` methods:

- `include(string ...$paths): self` adds source directories to measure. Default: none.
- `driver(string $driver): self` prefers a specific driver, for example `'pcov'`. Default: auto-detected.
- `export(string $format, string $target): self` adds an export. Formats: `json`, `lcov`, `clover`, `cobertura`, `html`. `$target` is a file path, or a directory for multi-file formats such as `html`. Repeatable.

```php
->coverage(fn ($c) => $c
    ->include('src')
    ->driver('pcov')
    ->export('lcov', 'coverage/lcov.info')
    ->export('html', 'coverage/html'))
```

When coverage is configured, the run prints a total percentage and writes each export. If no worker can collect coverage (no pcov, no Xdebug in coverage mode), the run warns on stderr but does not fail for that reason alone.

### watch(callable $configurator): self

Default: 200 ms debounce. The configurator receives a `WatchBuilder` with one method:

- `debounceMilliseconds(int $milliseconds): self` sets the quiet period. A re-run fires only once no further change has arrived for this long, so save bursts coalesce into one run. Must be at least 1.

```php
->watch(fn ($w) => $w->debounceMilliseconds(500))
```

### plugins(object ...$plugins): self

Default: none. Registers plugin instances. Repeatable; instances accumulate.

### failFast(bool $enabled = true): self

Default: off. When enabled, the run stops after the first failure.

### randomizeOrder(?int $seed = null): self

Default: declared order, no seed. Enables randomized class order. A null seed means one is chosen and printed at run time so a surprising ordering can be reproduced with `--seed`.

### build(): Configuration

Called by the loader, not by you. Validates the builder and produces the immutable configuration. Your config file returns the builder itself, without calling `build()`.

## CLI reference

```
greenlight [command] [options]
```

Commands:

- `run` discovers and executes tests. The default when no command is given.
- `list-tests` prints every discovered test id, one per line, followed by a count.
- `coverage:diff` compares two coverage JSON exports. Requires `--baseline=<path>` and `--current=<path>`; exits 1 when coverage regressed against the baseline.

Options:

- `--config=<path>` uses this config file instead of `./greenlight.php`.
- `--workers=<n|auto>` overrides the worker process count.
- `--bail[=<n>]` stops after `<n>` failures; bare `--bail` means 1.
- `--group=<name>` only runs tests in this group. Repeatable.
- `--filter=<pattern>` only runs tests whose id (`Class::method`, with the data-set label when present) matches. Case-insensitive substring by default; a pattern containing `*` or `?` must match the whole id. Repeatable; patterns union.
- `--failed` re-runs only the tests that did not pass in the previous run. Failure state is recorded on every run under the system temp dir; with no recorded state this is a usage error, and with an all-passing previous run it reports nothing to re-run and exits 0. When state exists, plain runs also order previously failed classes first (skipped under `--seed`).
- `--seed=<n>` randomizes class order with this seed.
- `--reporter=<name>` selects the output format: `tty`, `plain`, `junit`, `jsonl`, `github`, `teamcity`. Repeatable; multiple reporters write concurrently. Default: `tty` on an interactive terminal, otherwise `plain`. `tty` is parallel-aware: one live line per in-flight class with a spinner and running count, finalised in place as each class completes, so multi-worker interleaving never scrambles the display.
- `--watch` re-runs on file changes. Enter re-runs everything, q quits.
- `--detect-leaks` verifies every test instance is collected after its test; any leak fails the run.
- `--dry-run` prints the resolved configuration without executing.
- `-h, --help` shows the help text.
- `-V, --version` shows the version.

Exit codes: 0 success, 1 failure (including bad config, discovery errors, and zero discovered tests), 64 usage error.

The `tty` and `plain` reporters end with a "Slowest tests" block naming the ten slowest tests when any test took 200 ms or longer; fast suites print nothing extra.
