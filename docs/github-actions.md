# GitHub Actions

Greenlight stores run state in the system temporary directory by default. Run
state contains failed test IDs and class durations from the previous run.

The next run uses this data to put failed classes first. It then puts the
remaining classes in longest-first order. This order reduces idle worker time
near the end of a run.

## Cache run state

Configure a project state directory. Restore the state before the test step and
save it after the step.

For example, add this configuration to `greenlight.php`:

<!-- php-example {"mode":"display","reason":"Shows one method in an existing Greenlight configuration chain."} -->
```php
->storage(fn ($storage) => $storage
    ->stateDirectory('build/greenlight-state'))
```

This example keeps separate state for each operating system and PHP version:

```yaml
jobs:
  tests:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.4', '8.5']
    steps:
      - uses: actions/checkout@v6

      - name: Restore Greenlight run state
        uses: actions/cache/restore@v6
        with:
          path: build/greenlight-state/run-state.json
          key: greenlight-run-state-v1-${{ runner.os }}-php-${{ matrix.php }}-${{ github.run_id }}-${{ github.run_attempt }}
          restore-keys: greenlight-run-state-v1-${{ runner.os }}-php-${{ matrix.php }}-

      - name: Run tests
        id: tests
        run: vendor/bin/greenlight run

      - name: Save Greenlight run state
        if: ${{ !cancelled() && steps.tests.outcome != 'skipped' }}
        uses: actions/cache/save@v6
        with:
          path: build/greenlight-state/run-state.json
          key: greenlight-run-state-v1-${{ runner.os }}-php-${{ matrix.php }}-${{ github.run_id }}-${{ github.run_attempt }}
```

GitHub cache entries are immutable. The run ID and run attempt make each save
key unique. The restore prefix selects the most recent applicable entry.

Save state after a failed test run. A normal failed run contains useful
failure and duration data. Do not save state from a canceled job.

Add all settings that can materially change durations to the key. Examples
include the operating system, PHP version, dependency profile, suite, and
shard number.

Do not use one state file for multiple concurrent shards. Each shard records
only its selected tests and replaces the file contents.

The state is advisory. A missing or invalid file does not fail a normal run.
Do not put secrets in the cache path. Pull request workflows can read caches
from their base branch.

For cache matching and security rules, see the
[GitHub dependency caching reference](https://docs.github.com/en/actions/reference/workflows-and-actions/dependency-caching).

## Other Greenlight caches

Greenlight also stores discovery data and generated double proxies in the
system temporary directory by default. Configure these areas separately from
run state.

Do not cache discovery data across fresh GitHub Actions checkouts. Discovery
entries include file modification times, which usually change at checkout.
Greenlight rejects these stale entries.

Generated double proxies are executable PHP files. Their regeneration cost is
small, and restored executable caches need more trust. Do not cache them by
default.

Cache package downloads and static analysis data separately. Their invalidation
rules differ from the Greenlight run-state rules.
