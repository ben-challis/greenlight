# Configuration

Greenlight is configured with a single file: `greenlight.php` at the project
root.

The file returns a `Greenlight\Config\GreenlightConfig` builder. The CLI loads
the builder, applies command-line overrides, then freezes the result into an
immutable configuration object.

## Precedence

Configuration is applied in this order:

1. Built-in defaults
2. `greenlight.php`
3. CLI flags

Later layers override earlier ones.

For example, this config:

```php
->workers('auto')
```

combined with this CLI flag:

```sh
greenlight run --workers=1
```

runs with one worker.

## GreenlightConfig

Create the builder with:

```php
GreenlightConfig::create()
```

Every builder method returns `$this`, so calls can be chained.

### paths(array $tests): self

Default: `['tests']`.

Sets the directories Greenlight scans when no suite is selected.

Paths must be non-empty strings. The list itself must not be empty.

### suite(string $name, callable $configurator): self

Default: no suites.

Declares a named suite. The configurator receives a `SuiteBuilder` and must add
at least one path. Its return value is ignored, so arrow functions are fine.

Declaring the same suite name twice is an error.

```php
->suite('unit', fn ($s) => $s->in('tests/Unit'))
->suite('integration', fn ($s) => $s->in('tests/Integration')->tag('io'))
```

`SuiteBuilder` has two methods:

* `in(string ...$paths): self` adds directories to the suite. Required.
* `tag(string ...$tags): self` adds tags to the suite. Optional.

### workers(int|string $count = 'auto', ?int $recycleAfterTests = null, string $recycleAboveMemory = '256M'): self

Default: `'auto'` workers, recycled above `256M`, with no test-count recycling.

Workers pull one class at a time from a queue held by the orchestrator. As soon
as a worker finishes a class, it takes the next one. This avoids a worker sitting
idle behind another worker's long batch.

The queue is ordered longest-first using durations recorded from the previous
run. Classes that failed previously still go first.

Worker placement is load-dependent. The stable parts are:

* queue order for a given plan
* method order within each class under the selected seed
* per-class results

The seed reproduces ordering-related failures, not exact worker placement.

`$count` accepts a positive integer or `'auto'`. With `'auto'`, Greenlight uses
one worker per CPU core. Install the suggested `fidry/cpu-core-counter` package
for CPU detection that respects cgroup limits in containers.

A worker is recycled when either condition is met:

* it has run `$recycleAfterTests` tests
* its memory usage exceeds `$recycleAboveMemory`

`$recycleAboveMemory` is a size string such as `'256M'` or `'1G'`.

Count-based recycling is opt-in because every recycle requires a full worker
boot, and that worker's lane is idle during the boot. Use it for suites that
accumulate per-process state that memory checks cannot see, such as connections
or file handles.

### coverage(callable $configurator): self

Default: coverage off.

Calling `coverage()` enables coverage collection. The configurator receives a
`CoverageBuilder`.

Calling `coverage()` more than once reuses the same builder, so settings
accumulate.

`CoverageBuilder` methods:

* `include(string ...$paths): self` adds source directories to measure. Default:
  none.
* `driver(string $driver): self` prefers a specific driver, such as `'pcov'`.
  Default: auto-detected.
* `export(string $format, string $target): self` adds a coverage export.
  Supported formats are `json`, `lcov`, `clover`, `cobertura`, and `html`.
  `$target` is a file path, or a directory for multi-file formats such as
  `html`. Repeatable.

```php
->coverage(fn ($c) => $c
    ->include('src')
    ->driver('pcov')
    ->export('lcov', 'coverage/lcov.info')
    ->export('html', 'coverage/html'))
```

When coverage is configured, the run prints the total percentage and writes each
configured export.

If no worker can collect coverage because neither pcov nor Xdebug coverage mode
is available, Greenlight warns on stderr. That warning does not fail the run by
itself.

### watch(callable $configurator): self

Default: 200 ms debounce.

The configurator receives a `WatchBuilder`.

`WatchBuilder` has one method:

* `debounceMilliseconds(int $milliseconds): self` sets the quiet period before a
  rerun starts. The value must be at least 1.

A rerun fires only after no further file changes have arrived for the configured
period, so save bursts collapse into one run.

```php
->watch(fn ($w) => $w->debounceMilliseconds(500))
```

### failOnDeprecation(bool $enabled = true): self

Default: off.

Fails a test that otherwise passed if its captured diagnostics contain a
deprecation.

The change is recorded as a result transformation, so the exit code, `--bail`,
Junit output, and plugins all see the same final result.

This policy is applied by the worker after retries and after
`afterTest()` subscribers.

Also available as `--fail-on-deprecation`.

### failOnNotice(bool $enabled = true): self

Default: off.

Fails a test that otherwise passed if its captured diagnostics contain a notice.

Like `failOnDeprecation()`, the change is recorded as a result transformation
and is applied after retries and `afterTest()` subscribers.

Also available as `--fail-on-notice`.

### ignoreDeprecationsMatching(string ...$patterns): self

Default: none.

Exempts matching deprecations from `failOnDeprecation()`.

Patterns are matched case-insensitively. A plain pattern is treated as a
substring match. A pattern containing `*` or `?` must match the whole message.

The method is repeatable and patterns accumulate.

Use this for dependency deprecations you cannot fix yet.

### failOnRisky(bool $enabled = true): self

Default: off.

A test is risky if it passes without verifying any expectations. That means no
`Expect` calls and no mock expectations verified at teardown.

The `tty` and `plain` reporters list risky tests after the summary regardless of
this setting. Enabling `failOnRisky()` upgrades risky tests to failures.

A test that intentionally has no expectations can opt out with
`#[NoExpectations]`.

Also available as `--fail-on-risky`.

### plugins(object ...$plugins): self

Default: none.

Registers plugin instances.

The method is repeatable and instances accumulate.

### failFast(bool $enabled = true): self

Default: off.

Stops the run after the first failure.

### randomizeOrder(?int $seed = null): self

Default: declared order, no seed.

Randomizes class order.

If `$seed` is `null`, Greenlight chooses one at runtime and prints it, so the
same order can be reproduced with `--seed`.

### build(): Configuration

Called by the loader, not by user config.

`build()` validates the builder and returns the immutable configuration object.

Your `greenlight.php` should return the builder itself without calling
`build()`.

## Channels

Every worker process runs in a channel: a stable slot numbered from 1 to the
worker count.

Use the channel to derive external resources that parallel tests must not share,
such as database names, ports, virtual hosts, or temp directories.

Two tests running at the same time never share a channel. Channel numbers always
stay within 1 and the worker count, regardless of how many worker processes are
spawned during the run. When a worker is recycled or crashes, its replacement
reuses the freed slot.

A `--workers=1` run executes in-process on channel 1.

The channel is exposed in two ways:

* `GREENLIGHT_CHANNEL`, set in each worker environment for bootstrap files and
  tools that use `getenv()`
* `Greenlight\Core\Test\TestChannel`, available as a harness service for
  constructor injection and harness providers

`TestChannel->number` is the numeric slot.

`TestChannel->label()` returns `gl-<number>` for resource names.

Because channel slots are reused, resources derived from a channel can persist
across worker recycling. A replacement worker on channel 2 sees whatever the
previous channel-2 worker left behind.

This makes one-resource-per-channel setups cheap. For example, one database
schema can be created per channel and reused for the whole run.

## CLI reference

```sh
greenlight [command] [options]
```

## Commands

### run

Discovers and executes tests.

This is the default command when no command is given.

### list-tests

Prints every discovered test id, one per line, followed by a count.

### coverage:diff

Compares two coverage JSON exports.

Requires:

```sh
--baseline=<path>
--current=<path>
```

Exits with code 1 when coverage regressed against the baseline.

### profile:report

Renders a run profile from a saved jsonl event stream.

Requires:

```sh
--input=<path>
```

### ide-helper

Writes the IDE autocomplete helper for extension matchers.

Default output:

```sh
_greenlight_ide_helper.php
```

Override it with:

```sh
--output=<path>
```

Gitignore the generated file and regenerate it after changing matchers.

### completion

Prints a shell completion script to stdout.

Example setup:

```sh
source <(greenlight completion bash)
source <(greenlight completion zsh)
greenlight completion fish > ~/.config/fish/completions/greenlight.fish
```

For zsh, run `compinit` before sourcing the completion script.

## Options

### --config=<path>

Uses this config file instead of `./greenlight.php`.

### --workers=<n|auto>

Overrides the worker process count.

### --bail[=<n>]

Stops after `<n>` failures.

Bare `--bail` means `--bail=1`.

### --group=<name>

Runs only tests in the given group.

Repeatable.

### --filter=<pattern>

Runs only tests whose id matches the pattern.

A test id is `Class::method`, including the data-set label when present.

Matching is case-insensitive substring matching by default. A pattern containing
`*` or `?` must match the whole id.

Repeatable. Multiple filters are unioned.

### --shard=<n>/<m>

Runs the nth of m disjoint slices of the plan.

Shards are selected by stable class hash, so CI machines can split a suite
without coordination. The union of all shards is exactly the full suite.

Only whole classes move between shards. Individual methods are not split.

Combines with `--group` and `--filter` by sharding the filtered plan.

### --failed

Reruns only tests that did not pass in the previous run.

Failure state is recorded on every run under the system temp directory.

If no previous failure state exists, this is a usage error.

If the previous run passed completely, Greenlight reports that there is nothing
to rerun and exits 0.

When failure state exists, normal runs also place previously failed classes
first. This ordering is skipped under `--seed`.

### --seed=<n>

Randomizes class order with this seed.

Seeded runs skip timing-cache ordering, so the order is exactly the one produced
from the seed.

### --reporter=<name>

Selects the output format.

Supported reporters:

* `tty`
* `plain`
* `junit`
* `jsonl`
* `github`
* `teamcity`

Repeatable. Multiple reporters write concurrently.

Default: `tty` on an interactive terminal, otherwise `plain`.

The `tty` reporter is parallel-aware. It keeps one live line per in-flight class,
with a spinner and running count, and finalizes each line in place when the class
finishes. Multi-worker output does not interleave randomly.

The `teamcity` reporter includes IDE navigation metadata: `php_qn://` location
hints for click-to-source, plus a per-class `flowId` to keep parallel output
separated in JetBrains tools.

### --watch

Reruns on file changes.

While watching:

* Enter reruns everything.
* `q` quits.

### --detect-leaks

Verifies that every test instance is garbage-collected after its test.

Any detected leak fails the run.

### --fail-on-deprecation

Enables the deprecation policy for this run.

### --fail-on-notice

Enables the notice policy for this run.

### --fail-on-risky

Enables the risky-test policy for this run.

### --profile

Adds a run profile after the summary.

The profile includes:

* workers requested
* workers spawned
* workers recycled
* average boot latency, measured from spawn to first class
* per-worker busy time and utilization
* makespan spread between the first and last worker to finish
* the ten slowest classes

The profile is derived entirely from the event stream. A saved jsonl artifact can
be rendered later with:

```sh
greenlight profile:report --input=<file>
```

### --dry-run

Prints the resolved configuration without executing tests.

### --verbose

In interactive output, prints a permanent line for every completed class.

### --no-ansi

Disables colours and the live progress window. Output becomes plain and
append-only.

A truthy `CI` environment variable has the same effect.

`NO_COLOR` disables colours only.

### -h, --help

Shows help.

### -V, --version

Shows the version.

## Exit codes

Greenlight uses these exit codes:

* `0`: success
* `1`: failure, including failing or erroring tests, invalid config, discovery
  errors, coverage export errors, detected leaks, and zero discovered tests
* `64`: usage error, such as an unknown command, unknown flag, or malformed
  option value

A run that discovers zero tests is treated as a configuration problem, not a
success.

## Interruption

The first Ctrl+C, SIGINT, or SIGTERM starts a graceful shutdown.

During graceful shutdown, Greenlight:

* stops assigning new work
* lets workers finish their in-flight test
* drains worker output
* prints the summary for completed work
* records the failure state used by `--failed`
* records the timing cache
* restores the terminal when exiting watch mode

The run then exits with:

* `130` for SIGINT
* `143` for SIGTERM

A second signal during shutdown terminates immediately.

Graceful shutdown requires `ext-pcntl`. Without it, PHP uses its default signal
behaviour and exits at once.

## Discovery cache

Greenlight caches discovery results per file under the system temp directory.

The cache key includes the file path, mtime, and size. Unchanged files can skip
re-parsing on the next run. If anything is uncertain, Greenlight parses the file
again.

Watch mode benefits most because every iteration rediscovers the suite.

## Interactive output

In an interactive terminal, the `tty` reporter shows a bounded live window:

* a progress counter
* in-flight classes
* at most ten live lines, clamped to the terminal height

Failures and skips are printed permanently as soon as their class finishes.

Passing classes only advance the counter unless `--verbose` is enabled.

Both human reporters start with a one-line header containing:

* Greenlight version
* PHP version
* config file
* seed, when randomized
* worker count

They end with a "Slowest tests" block when any test took at least 500 ms. The
block lists the five slowest tests.

Fast suites do not print the block.

With `--profile`, the slowest-test list is extended to 25 entries.
