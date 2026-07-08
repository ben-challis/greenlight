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

Workers pull one class at a time from an orchestrator-held queue as they finish, so a worker never idles behind another's long bucket, and the queue is ordered longest first from durations recorded on the previous run (previously failed classes still go first). Worker placement is therefore load-dependent; what stays deterministic is the queue order for a given plan, within-class method order under the seed, and per-class results. The seed reproduces failures, not placement. Default: `'auto'` workers, recycling after 500 tests or above 256M. `$count` is a positive integer or the string `'auto'` (one worker per CPU core; install the suggested `fidry/cpu-core-counter` for detection that respects cgroup limits in containers). A worker is recycled once it has executed `$recycleAfterTests` tests or its memory use exceeds `$recycleAboveMemory` (a size string such as `'256M'` or `'1G'`).

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

### failOnDeprecation(bool $enabled = true): self, failOnNotice(bool $enabled = true): self

Default: off. Fails a passed test whose captured output contains a deprecation or notice, with the diagnostic as the failure detail; the flip is recorded as a provenance transformation, so every consumer (exit code, `--bail`, junit, plugins) sees the same truth. Applied by the worker to the final result, after retries and afterTest subscribers. Also available as `--fail-on-deprecation` and `--fail-on-notice`.

### ignoreDeprecationsMatching(string ...$patterns): self

Default: none. Exempts deprecation messages from `failOnDeprecation()`: case-insensitive substring, or whole-message match when the pattern contains `*` or `?`. Repeatable; patterns accumulate. This is the escape hatch for dependency noise you cannot fix.

### failOnRisky(bool $enabled = true): self

Default: off. A passed test that verified no expectations (nothing through `Expect`, no mock expectations verified at teardown) is marked risky; the `tty` and `plain` reporters list risky tests after the summary either way, and this setting (or `--fail-on-risky`) upgrades them to failures. A test that legitimately asserts nothing opts out with `#[NoExpectations]`.

### plugins(object ...$plugins): self

Default: none. Registers plugin instances. Repeatable; instances accumulate.

### failFast(bool $enabled = true): self

Default: off. When enabled, the run stops after the first failure.

### randomizeOrder(?int $seed = null): self

Default: declared order, no seed. Enables randomized class order. A null seed means one is chosen and printed at run time so a surprising ordering can be reproduced with `--seed`.

### build(): Configuration

Called by the loader, not by you. Validates the builder and produces the immutable configuration. Your config file returns the builder itself, without calling `build()`.

## Channels

Every worker process runs in a channel: a stable slot numbered 1 to the worker count. Use it to derive external resources that parallel tests must not share, such as database names, ports, virtual hosts, or temp directories. Two tests running at the same time never share a channel, and the numbers stay within 1 to the worker count no matter how many worker processes a run spawns: when a worker is recycled or crashes, its replacement reuses the freed slot. A `--workers=1` run executes in-process on channel 1.

The channel is exposed two ways, always in agreement:

- The `GREENLIGHT_CHANNEL` environment variable, set in each worker's environment, for bootstrap files and spawned tools that read `getenv()`.
- The `Greenlight\Core\Test\TestChannel` harness service, for constructor injection into tests and for harness providers. `TestChannel->number` is the slot; `TestChannel->label()` returns `gl-<number>` for resource names.

Because slots are reused, channel-derived resources persist across worker recycling: a replacement worker on channel 2 sees whatever the previous channel-2 worker left behind. That is what makes patterns like one database schema per channel cheap, since the schema is created once and reused for the whole run.

## CLI reference

```
greenlight [command] [options]
```

Commands:

- `run` discovers and executes tests. The default when no command is given.
- `list-tests` prints every discovered test id, one per line, followed by a count.
- `coverage:diff` compares two coverage JSON exports. Requires `--baseline=<path>` and `--current=<path>`; exits 1 when coverage regressed against the baseline.
- `profile:report` renders the run profile from a saved jsonl event stream. Requires `--input=<path>`.
- `ide-helper` writes the IDE autocomplete helper for extension matchers to `--output=<path>` (default `_greenlight_ide_helper.php`). Gitignore it and regenerate after changing matchers.
- `completion` prints a shell completion script to stdout. Wire it up with `source <(greenlight completion bash)`, `source <(greenlight completion zsh)` (after `compinit`), or `greenlight completion fish > ~/.config/fish/completions/greenlight.fish`.

Options:

- `--config=<path>` uses this config file instead of `./greenlight.php`.
- `--workers=<n|auto>` overrides the worker process count.
- `--bail[=<n>]` stops after `<n>` failures; bare `--bail` means 1.
- `--group=<name>` only runs tests in this group. Repeatable.
- `--filter=<pattern>` only runs tests whose id (`Class::method`, with the data-set label when present) matches. Case-insensitive substring by default; a pattern containing `*` or `?` must match the whole id. Repeatable; patterns union.
- `--shard=<n>/<m>` runs the nth of m disjoint slices of the plan, selected by stable class hash, so CI machines split a suite with no coordination: the union of all shards is exactly the full suite. Whole classes relocate, never single methods. Combines with `--group` and `--filter` by sharding the filtered plan.
- `--failed` re-runs only the tests that did not pass in the previous run. Failure state is recorded on every run under the system temp dir; with no recorded state this is a usage error, and with an all-passing previous run it reports nothing to re-run and exits 0. When state exists, plain runs also order previously failed classes first (skipped under `--seed`).
- `--seed=<n>` randomizes class order with this seed. Seeded runs also skip the timing-cache ordering below, so the randomized order stays exactly what the seed says.
- `--reporter=<name>` selects the output format: `tty`, `plain`, `junit`, `jsonl`, `github`, `teamcity`. Repeatable; multiple reporters write concurrently. Default: `tty` on an interactive terminal, otherwise `plain`. `tty` is parallel-aware: one live line per in-flight class with a spinner and running count, finalised in place as each class completes, so multi-worker interleaving never scrambles the display. `teamcity` embeds IDE navigation metadata: `php_qn://` location hints for click-to-source and a per-class flowId that keeps interleaved parallel output untangled in JetBrains consumers.
- `--watch` re-runs on file changes. Enter re-runs everything, q quits.
- `--detect-leaks` verifies every test instance is collected after its test; any leak fails the run.
- `--fail-on-deprecation`, `--fail-on-notice`, `--fail-on-risky` enable the matching config policies for this run.
- `--profile` appends a run profile after the summary: workers requested, spawned, and recycled, average boot latency (spawn to first class), per-worker busy time and utilisation, the makespan spread between the first and last worker to finish, and the ten slowest classes. Derived entirely from the event stream, so `greenlight profile:report --input=<file>` reproduces the same block offline from a saved jsonl artifact.
- `--dry-run` prints the resolved configuration without executing.
- `--verbose` prints a permanent line per completed class in interactive output.
- `--no-ansi` disables colours and the live progress window; output becomes plain and append-only. A truthy `CI` environment variable has the same effect, and `NO_COLOR` disables colours only.
- `-h, --help` shows the help text.
- `-V, --version` shows the version.

Exit codes: 0 success, 1 failure (including bad config, discovery errors, and zero discovered tests), 64 usage error.

Interruption: the first Ctrl+C (SIGINT) or SIGTERM starts a graceful shutdown. No new work is assigned, workers finish their in-flight test and drain, the reporter prints the summary for everything that completed, and the failure state used by `--failed` and the timing cache is still recorded. Watch mode restores the terminal on the way out. The run then exits 130 for SIGINT or 143 for SIGTERM, and a second signal during the drain terminates immediately. Graceful shutdown requires ext-pcntl; without it the process keeps PHP's default behaviour and exits at once.

Discovery caches per-file results (keyed by path, mtime, and size) under the system temp dir, so unchanged files skip re-parsing on the next run; any doubt falls back to parsing. Watch mode benefits most, since every iteration re-discovers.

In an interactive terminal the `tty` reporter shows a bounded live window: a progress counter and the in-flight classes (at most ten lines, clamped to the terminal height), with failures and skips printed permanently the moment their class finishes. Cleanly passing classes only advance the counter; `--verbose` restores a line per class. Both human reporters start with a one-line header (version, PHP version, config file, seed when randomized, worker count) and end with a "Slowest tests" block naming the five slowest tests when any test took 500 ms or longer; fast suites print nothing extra. `--profile` extends the list to twenty-five entries.
