# Configuration

Configure Greenlight with one `greenlight.php` file at the project root.

The file returns a `Greenlight\Config\GreenlightConfig` builder. The CLI loads
the builder and applies command-line overrides. It then creates an immutable
configuration object.

## Precedence

Greenlight applies configuration in this order:

1. Built-in defaults
2. `greenlight.php`
3. CLI flags

Later layers override earlier ones.

For example, use this configuration:

<!-- php-example {"example":"configuration-example-01","file":"snippet.php","mode":"statements","tools":["rector"]} -->
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

<!-- php-example {"example":"configuration-example-02","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
GreenlightConfig::create()
```

Every builder method returns `$this`. Thus, you can chain method calls.

### `paths(array $tests): self`

Default: `['tests']`.

Sets the top-level directories that Greenlight scans.

Paths must be non-empty strings. The list itself must not be empty.

Each run also scans every path from `suite()`.

### `suite(string $name, callable $configurator): self`

Default: no suites.

Declares a named suite. Greenlight gives a `SuiteBuilder` to the configurator.
The configurator must add at least one path. Greenlight ignores its return
value. Thus, you can use an arrow function.

Each run includes every named suite. Suite names and tags are descriptive. A
suite does not create a selection or execution boundary. It does not cause
lifecycle events. The `--dry-run` and `--list-suites` options print suites.

A second declaration with the same suite name causes an error.

<!-- php-example {"example":"configuration-example-03","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
->suite('unit', fn ($s) => $s->in('tests/Unit'))
->suite('integration', fn ($s) => $s->in('tests/Integration')->tag('io'))
```

`SuiteBuilder` has two methods:

* `in(string ...$paths): self` adds directories to the suite. Required.
* `tag(string ...$tags): self` adds tags to the suite. Optional.

### `workers(int|string $count = 'auto', ?int $recycleAfterTests = null, string $recycleAboveMemory = '256M'): self`

Default: `'auto'` workers, a `256M` memory recycle limit, and no test-count
recycle limit.

A worker requests one class at a time. When the worker finishes that class, it
requests the next class.

Greenlight keeps this class-level schedule by default. Add `#[AllowParallel]`
to an independent large class to make each test or data set a separate
assignment.

The orchestrator gives first priority to classes that failed in the previous
run. It orders the other classes by previous duration, longest first.

Worker placement is load-dependent. The stable parts are:

* queue order for a given plan
* method and data-set order in the execution plan
* assignment queue order for entries from `#[AllowParallel]` classes

The seed reproduces failures related to order. It does not reproduce exact
worker placement or completion-event order.

`$count` accepts a positive integer or `'auto'`. With `'auto'`, Greenlight uses
one worker per CPU core. Install the suggested `fidry/cpu-core-counter` package
for CPU detection that respects cgroup limits in containers.

Greenlight recycles a worker when one of these conditions is true:

* it has run `$recycleAfterTests` tests
* its memory usage exceeds `$recycleAboveMemory`

`$recycleAboveMemory` is a size string such as `'256M'` or `'1G'`.

Test-count recycle is optional because each recycle requires a complete worker
boot. The worker channel is idle during the boot. Use this limit for suites
that collect per-process state that memory checks cannot detect. Examples
include connections and file handles.

### `resourceLimit(string $name, int $limit = 1): self`

Default: `1` for a resource used by `#[RequiresResource]`.

Limits how many assignments in one Greenlight run can use the named resource at
once.

<!-- php-example {"example":"configuration-example-04","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
return GreenlightConfig::create()
    ->resourceLimit('postgres', 3)
    ->resourceLimit('payments-sandbox');
```

Limits must be positive. Names must match `[a-z0-9][a-z0-9._-]*`. Configure
each name only one time.

An assignment that requires several resources waits until all of them have
capacity. Greenlight claims the slots together and sends the assignment only
after all slots are available.

The limit is an in-memory scheduler gate, not a distributed lock. Separate
Greenlight processes, worktrees, and CI shards do not share capacity.

The scheduler does not choose a concrete resource instance or expose a lease
identifier. If a test needs one of several distinct instances, the application
must allocate it. Use a channel instead when every worker can have its own
instance.

### `coverage(callable $configurator): self`

Default: coverage off.

The `coverage()` method enables coverage collection. Greenlight gives a
`CoverageBuilder` to the configurator.

Multiple calls to `coverage()` use the same builder. Thus, its settings
accumulate.

`CoverageBuilder` methods:

* `include(string ...$paths): self` adds source directories to measure. Default:
  none.
* `driver(string $driver): self` restricts coverage to `pcov` or `xdebug` when
  you use that value. Other non-empty values have the default behavior:
  Greenlight tries pcov, then Xdebug.
* `export(string $format, string $target): self` adds a coverage export.
  Supported formats are `json`, `lcov`, `clover`, `cobertura`, and `html`.
  `$target` is a file path, or a directory for multi-file formats such as
  `html`. Repeatable.

<!-- php-example {"example":"configuration-example-05","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
->coverage(fn ($c) => $c
    ->include('src')
    ->driver('pcov')
    ->export('lcov', 'coverage/lcov.info')
    ->export('html', 'coverage/html'))
```

When you configure coverage, the run prints the total percentage and writes
each coverage export.

If no worker can collect coverage because neither pcov nor Xdebug coverage mode
is available, Greenlight warns on stderr. That warning does not fail the run by
itself.

The workers are not the only measured processes. In a parallel run, the
orchestrator collects its own coverage. Thus, the export includes code that
executes only in the orchestrator. A coverage run also exports
`GREENLIGHT_COVERAGE_DIR` and `GREENLIGHT_COVERAGE_INCLUDE` to each process that
it starts. A `bin/greenlight` process writes coverage to the shared directory
if it inherits these variables. For example, an acceptance test can start this
process to operate the real CLI.

Before export, the run adds these coverage files to the
result. This collection operation does not fail the run. The configured include
paths filter coverage from all processes.

### `watch(callable $configurator): self`

Default: 200 ms debounce.

The configurator receives a `WatchBuilder`.

`WatchBuilder` has one method:

* `debounceMilliseconds(int $milliseconds): self` sets the quiet period before a
  rerun starts. The value must be at least 1.

A rerun starts after the configured period has no file changes. Thus, a group
of save operations starts only one run.

<!-- php-example {"example":"configuration-example-06","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
->watch(fn ($w) => $w->debounceMilliseconds(500))
```

### `failOnDeprecation(bool $enabled = true): self`

Default: off.

Fails a test that otherwise passed if its captured diagnostics contain a
deprecation.

The worker records the change as a result transformation. Thus, the exit code,
`--bail`, JUnit output, and plugins use the same final result.

The worker applies this policy after retries and `afterTest()` subscribers.

Also available as `--fail-on-deprecation`.

### `failOnNotice(bool $enabled = true): self`

Default: off.

Fails a test that otherwise passed if its captured diagnostics contain a notice.

As with `failOnDeprecation()`, the worker records the change as a result
transformation. It applies the change after retries and `afterTest()`
subscribers.

Also available as `--fail-on-notice`.

### `ignoreDeprecationsMatching(string ...$patterns): self`

Default: none.

Exempts deprecations that match a pattern from `failOnDeprecation()`.

Greenlight compares patterns without regard to case. A plain pattern matches a
part of the message. A pattern that contains `*` or `?` must match the complete
message.

The method is repeatable and patterns accumulate.

Use this for dependency deprecations you cannot fix yet.

### `failOnRisky(bool $enabled = true): self`

Default: off.

A successful test is risky if it does not verify an expectation. Such a test has
no `Expect` calls and no mock expectations that Greenlight verifies at
teardown.

The `tty` and `plain` reporters always list risky tests after the summary. The
`failOnRisky()` method changes risky tests to failures.

Use `#[NoExpectations]` for a test that intentionally verifies no expectations.

Each `eventually()` or `consistently()` matcher counts once.

Also available as `--fail-on-risky`.

### `plugins(Plugin ...$plugins): self`

Default: none.

Registers Greenlight plugin instances. Each plugin implements one or more
capability interfaces.

The method is repeatable and instances accumulate.

A `ReporterProvider` plugin registers custom names for `--reporter`. See
[plugins](plugins.md#reporterprovider).

### `artifacts(callable $configurator): self`

Default: output below `build/greenlight-artifacts`, with failure-only retention.

Greenlight gives an `ArtifactBuilder` to the configurator. Repeated calls use
the same builder.

<!-- php-example {"example":"configuration-example-07","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
->artifacts(fn ($artifacts) => $artifacts
    ->directory('build/test-evidence')
    ->maxAttachmentsPerTest(20)
    ->maxAttachmentSize('10M')
    ->maxTestSize('50M')
    ->maxRunAttachments(2_000)
    ->maxRunSize('500M'))
```

Defaults are 32 attachments and 100 MiB per test. Each attachment has a 25 MiB
limit. Each run has limits of 10,000 attachments and 1 GiB. Per-test limits
include all retry attempts, even if retention policy discards attachments
later. Greenlight releases run quota when it discards an attachment.

See [test attachments](attachments.md) for the runtime API and security model.

### `storage(callable $configurator): self`

Default: all storage uses the system temporary directory.

The configurator receives a `StorageBuilder`. Repeated calls use the same
builder.

Use `rootDirectory()` to put all Greenlight storage below one directory. An
area-specific directory replaces its directory below the root.

`StorageBuilder` has these methods:

* `rootDirectory(string $directory): self` sets the common storage root.
* `stateDirectory(string $directory): self` stores prior failures and the
  timing cache.
* `cacheDirectory(string $directory): self` stores discovery metadata.
* `generatedCodeDirectory(string $directory): self` stores generated double
  proxy PHP.
* `temporaryDirectory(string $directory): self` stores run-owned temporary
  data.

Relative directories resolve against the initial project working directory.
Greenlight creates missing directories when a storage product first writes
data.

Use separate areas when their retention or trust requirements differ:

<!-- php-example {"example":"configuration-example-08","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
->storage(fn ($storage) => $storage
    ->stateDirectory('build/greenlight-state')
    ->cacheDirectory('build/greenlight-cache')
    ->generatedCodeDirectory('/var/tmp/greenlight-code')
    ->temporaryDirectory('/var/tmp/greenlight'))
```

The state directory contains `run-state.json`. This file has the failed test
IDs and class durations from the previous run.

Do not share one state directory between concurrent shards. Each shard writes
one complete snapshot and can replace data from another shard.

Generated proxy files contain executable PHP. Use only a private, trusted
directory. Do not restore this directory from an untrusted cache.

Greenlight does not remove configured roots. It removes only temporary child
directories that it creates and owns.

### `failFast(bool $enabled = true): self`

Default: off.

Stops the run after the first failure.

### `randomizeOrder(?int $seed = null): self`

Default: declared order, no seed.

Randomizes class order.

If `$seed` is `null`, Greenlight selects and prints a seed at run time. Use
`--seed` with that value to reproduce the same order.

### `build(): Configuration`

The loader calls this method. User configuration does not call it.

`build()` validates the builder and returns the immutable configuration object.

Return the builder from `greenlight.php`. Do not call `build()`.

## Channels and resource limits

Every worker process runs in a channel: a stable slot numbered from 1 to the
worker count.

Use the channel to derive external resources that parallel tests must not share.
Examples include database names, ports, virtual hosts, and temporary
directories.

Use a channel when each worker can have a separate resource. Use
`#[RequiresResource]` when workers share a dependency with lower safe
concurrency. A resource limit controls the number of assignments that can run.
It does not assign a resource instance to an assignment.

Two concurrent tests do not share a channel. Channel numbers are from 1 through
the worker count. The number of worker processes during the run does not change
this range. After a worker recycle or crash, its replacement reuses the freed
slot.

A `--workers=1` run executes in-process on channel 1.

Greenlight makes the channel available in two ways:

* `GREENLIGHT_CHANNEL`, set in each worker environment for bootstrap files and
  tools that use `getenv()`
* `Greenlight\Core\Test\TestChannel`, available as a harness service for
  constructor injection and harness providers

`TestChannel->number` is the numeric slot.

`TestChannel->label()` returns `gl-<number>` for resource names.

Because Greenlight uses channel slots again, channel resources can persist
after a worker recycle. A replacement worker on channel 2 can access resources
from the previous channel-2 worker.

This design reduces the cost of one-resource-per-channel setups. For example,
you can create one database schema per channel and use it for the complete run.

A test can use both. Its channel can select a private database while
`#[RequiresResource('payments-sandbox')]` limits access to a shared external
service.

For infrastructure that must be created and destroyed with the run, a plugin
can implement `IntegrationFixtureProvider`. The provider runs in the
orchestrator after discovery and sharding, creates shared or per-channel
resources, and registers teardown. Workers receive an injectable
`IntegrationResources` catalog containing shared values plus only their own
channel overlay. See [Writing plugins](plugins.md#integrationfixtureprovider).

## CLI reference

```sh
greenlight [command] [options]
```

## Commands

### run

Discovers and executes tests.

This is the default command if you do not give a command.

### list-tests

Prints each discovered test ID on a separate line. It then prints the count.

### `coverage:diff`

Compares two coverage JSON exports.

Requires:

```sh
--baseline=<path>
--current=<path>
```

Exits with code 1 if total coverage decreases or the current export has a new
uncovered line. A total coverage gain does not hide a new uncovered line.

See the [coverage JSON schema](architecture/coverage-json.md) for the required
format and path rules.

### profile:report

Renders a run profile from a saved JSONL event stream.

The command accepts JSONL versions 2 and 3.

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

Add the generated file to `.gitignore`. Regenerate the file after a matcher
change.

### completion

Prints a shell completion script to stdout.

Example setup:

```sh
source <(greenlight completion bash)
source <(greenlight completion zsh)
greenlight completion fish > ~/.config/fish/completions/greenlight.fish
```

For zsh, run `compinit` before you source the completion script.

## Options

### `--config=<path>`

Uses this configuration file instead of `./greenlight.php`.

### `--workers=<n|auto>`

Overrides the worker process count.

### `--resource-limit=<name>=<n>`

Sets a resource limit for this run and overrides `greenlight.php`.

```sh
vendor/bin/greenlight run \
    --resource-limit=postgres=2 \
    --resource-limit=payments-sandbox=1
```

Repeat the option to set limits for different resources. Use each name only one
time.

### `--bail[=<n>]`

Stops after `<n>` failures.

Bare `--bail` means `--bail=1`.

### `--group=<name>`

Runs only tests in the given group.

Repeatable.

### `--filter=<pattern>`

Runs only tests with a test ID that matches the pattern.

A test ID is `Class::method` with an optional data-set label.

By default, Greenlight matches substrings without regard to case. A pattern
that contains `*` or `?` must match the complete test ID.

Repeatable. Multiple filters form a union.

### `--test-id=<id>`

Runs only the exact test ID. Unlike `--filter`, this option does not compare
substrings or wildcard patterns.

Use the test IDs printed by `list-tests` or `--list-tests`. Include the data-set
label when present:

```sh
greenlight run \
    '--test-id=App\Tests\OrderTest::placesOrder[card]'
```

Repeatable. Multiple exact test IDs and `--filter` patterns form a union.
Exclusions have precedence.

### `--exclude-group=<name>`

Excludes tests in the given group.

Repeatable. Exclusions take precedence over `--group` and `--filter`.

### `--exclude-class=<pattern>`

Excludes classes with names that match the pattern.

Greenlight matches class names with case sensitivity. A pattern without a
wildcard matches a substring. A pattern that contains `*` or `?` must match the
complete class name.

Repeatable.

### `--exclude-method=<pattern>`

Excludes methods with names that match the pattern.

Greenlight matches method names with case sensitivity. A pattern without a
wildcard matches a substring. A pattern that contains `*` or `?` must match the
complete method name.

Repeatable.

### `--exclude-path=<prefix>`

Excludes tests whose source file is below the path prefix.

Greenlight resolves relative prefixes from the current directory. Repeatable.

### `--list-tests`

Prints the selected test IDs. It does not run them.

### `--list-groups`

Prints each selected group and its test count. It does not run tests.

### `--list-suites`

Prints the configured named suites. It does not discover or run tests.

### `--repeat=<n>`

Runs the selected tests in `<n>` fresh iterations.

The command exits with a nonzero code if an iteration fails. `--repeat=1` is
equivalent to an ordinary run.

### `--repeat-until-failure`

Repeats fresh runs until an iteration fails.

On its own, the command stops after 100 successful iterations. Use
`--repeat=<n>` to set a different limit. Do not use it with `--watch`.

### `--shard=n/m`

Runs the nth of m disjoint slices of the plan.

Greenlight selects shards by a stable class hash. Thus, CI machines can split a
suite without coordination. Together, all shards contain the complete suite.

Only whole classes move between shards. Individual methods are not split.

Combines with `--group` and `--filter`. Greenlight divides the filtered plan
into shards.

Each shard enforces its own resource limits. If four shards each use
`postgres=2`, up to eight tests can use PostgreSQL across the CI job.

### `--failed`

Reruns only tests that failed or had an error in the previous run.

Greenlight records failure state for each run in the system temporary
directory.

If no previous failure state exists, this is a usage error.

If the previous run passed completely, Greenlight reports that there is nothing
to rerun and exits 0.

When failure state exists, normal runs put previously failed classes first.
`--seed` disables this order.

### `--seed=<n>`

Randomizes class order with this seed.

Seeded runs do not use the timing-cache order. The seed determines the complete
order.

### `--reporter=<name>`

Selects the output format.

Built-in reporters:

* `tty`
* `plain`
* `junit`
* `jsonl`
* `github`
* `teamcity`

Repeatable. Multiple reporters write concurrently.

`ReporterProvider` plugins can add names. Greenlight creates reporters in flag
order. A repeated name creates a separate reporter for each occurrence.

All selected reporters use the same Greenlight-owned standard output. A custom
reporter MUST NOT close this output.

Reporter names MUST be unique across built-ins and plugins. A duplicate name
stops the command before test execution.

Shell completions suggest built-in names. Configured names remain valid.

Default: `tty` on an interactive terminal, otherwise `plain`.

The `tty` reporter supports parallel work. It keeps one live line for each
active class. Each line has a spinner and a current count. The reporter
completes the line in place when the class finishes. Multi-worker output does
not interleave randomly.

The `teamcity` reporter includes IDE navigation metadata: `php_qn://` location
hints for click-to-source, plus a per-class `flowId` to keep parallel output
separated in JetBrains tools.

### `--artifacts-dir=<path>`

Overrides the configured artifact parent directory for this run. Greenlight
creates a unique run directory below it and reports that path in human and
machine-readable output.

### `--baseline=<path>`

Sets the baseline coverage JSON file for `coverage:diff`.

### `--current=<path>`

Sets the current coverage JSON file for `coverage:diff`.

### `--watch`

Starts with a complete run, then reruns all selected tests after a file change.

Greenlight watches all configured test paths and coverage include paths. After
a change, classes that failed in the previous watch iteration run first.

Watch mode does not publish coverage totals or coverage exports.

In watch mode:

* Enter reruns all selected tests.
* `q` quits with exit code 0, regardless of the last iteration result.

Signals use the exit codes in the [interruption](#interruption) section.

### `--detect-leaks`

Verifies that garbage collection removes each test instance after its test.

A detected leak fails the run.

Xdebug develop mode can retain caught exceptions and report false leaks. Use
`XDEBUG_MODE=off` to get correct leak results.

### `--fail-on-deprecation`

Enables the deprecation policy for this run.

### `--fail-on-notice`

Enables the notice policy for this run.

### `--fail-on-risky`

Enables the risky-test policy for this run.

### `--profile`

Adds a run profile after the summary.

The profile includes:

* workers requested
* workers spawned
* workers recycled
* average spawn-to-hello time
* average hello-to-ready bootstrap time
* average ready-to-first-assignment time
* total time between assignments
* idle time from the bootstrap barrier, resource capacity, and no queued work
* average time from a retirement request to observed process exit
* per-worker class count, busy time, and utilization for non-isolated workers
* workers that run isolated tests
* makespan spread between the first and last worker to finish
* the ten slowest classes

The event stream supplies all profile data. You can render a saved JSONL
artifact later with:

```sh
greenlight profile:report --input=<file>
```

### `--input=<path>`

Sets the JSONL input file for `profile:report`.

### `--output=<path>`

Changes the file written by `ide-helper`.

Default: `_greenlight_ide_helper.php`.

### `--dry-run`

Prints a summary of the resolved run settings without test discovery or
execution.

### `--verbose`

In interactive output, prints a permanent line for every completed class.

### `--ansi`

Enables colors in append-only reporter output. It does not enable the live
progress window.

### `--no-ansi`

Disables colors and the live progress window. Output becomes plain and
append-only.

A truthy `CI` environment variable has the same effect.

`NO_COLOR` disables colors only. `NO_COLOR` and `--no-ansi` have priority over
`--ansi`.

### -h, --help

Shows help.

### -V, --version

Shows the version.

## Exit codes

Greenlight uses these exit codes:

* `0`: success
* `1`: failure from failed tests, test errors, invalid configuration, discovery
  errors, coverage export errors, detected leaks, and zero discovered tests
* `64`: usage error, such as an unknown command, unknown flag, or malformed
  option value

Greenlight treats a run with zero tests as a configuration problem. It does not
treat the run as a success.

## Interruption

The first Ctrl+C, SIGINT, or SIGTERM starts a graceful shutdown.

During graceful shutdown, Greenlight:

* stops new assignments
* lets workers finish their in-flight test
* drains worker output
* prints the summary for completed work
* records the failure state used by `--failed`
* records class durations in the timing cache
* restores the terminal when it exits watch mode

The run then exits with:

* `130` for SIGINT
* `143` for SIGTERM

A second signal during shutdown terminates immediately.

Graceful shutdown requires `ext-pcntl`. Without it, PHP uses its default signal
behavior and exits immediately.

## Discovery cache

Greenlight caches discovery results per file under the system temp directory.

The cache key includes the file path, mtime, and size. Greenlight can reuse
discovery data for an unchanged file in the next run. If the cache data is
uncertain, Greenlight parses the file again.

Watch mode benefits most because every iteration rediscovers the suite.

## Interactive output

In an interactive terminal, the `tty` reporter shows a bounded live window:

* a progress counter
* in-flight classes
* at most ten live lines, clamped to the terminal height

The reporter prints failures and skips permanently when their class finishes.

Classes that pass only advance the counter unless you enable `--verbose`.

Both human reporters start with a one-line header that contains:

* Greenlight version
* PHP version
* configuration file
* seed, when randomized
* worker count

They end with a "Slowest tests" block when a test took at least 500 ms. The
block lists the five slowest tests.

Fast suites do not print the block.

`--profile` extends the slowest-test list to 25 entries.
