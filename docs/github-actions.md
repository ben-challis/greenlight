# GitHub Actions

Greenlight can publish test annotations and retained attachments in GitHub
Actions. It can also reuse run state and merge coverage from parallel shards.

## Run Greenlight

Use this workflow to run Greenlight without additional CI integration:

```yaml
name: Tests

on:
  pull_request:
  push:
    branches: [main]

permissions:
  contents: read

jobs:
  tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --no-progress --prefer-dist

      - name: Run tests
        run: vendor/bin/greenlight run
```

Greenlight returns a nonzero exit code when the run fails. GitHub Actions then
marks the job as failed. The following sections add optional CI integrations.

## Publish test results and attachments

The `github` reporter writes GitHub workflow annotations for failed and errored
tests. It also writes a warning when a test passes after a retry. The `plain`
reporter keeps a human-readable test log.

Configure a project-relative parent directory for retained attachments:

<!-- php-example {"mode":"display","reason":"Shows a configuration call after omitted calls."} -->
```php
return GreenlightConfig::create()
    // ...
    ->artifacts(fn ($artifacts) => $artifacts
        ->directory('build/github/greenlight-runs'));
```

Greenlight creates a unique run directory below this parent. A run without
retained attachments does not create an empty directory.

This workflow publishes annotations and uploads retained attachments after a
successful or failed test run:

```yaml
name: Tests

on:
  pull_request:
  push:
    branches: [main]

permissions:
  contents: read

jobs:
  tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --no-progress --prefer-dist

      - name: Run tests
        shell: bash
        run: >-
          vendor/bin/greenlight run
          --reporter=plain
          --reporter=github

      - name: Upload retained attachments
        if: ${{ !cancelled() }}
        uses: actions/upload-artifact@v7
        with:
          name: greenlight-test-evidence-${{ github.run_attempt }}
          path: build/github/greenlight-runs/
          if-no-files-found: ignore
```

The `github` reporter prints attachment paths and a notice with the Greenlight
run directory. It does not upload attachment content. The upload step stores
that content with the workflow run.

The upload step runs after a passed or failed test command. It does not run
after job cancellation. The `ignore` value is intentional because a run with
no retained attachments has no directory to upload.

For a matrix job, add the matrix values to the artifact name. Each job must use
a different artifact name.

Greenlight does not remove secrets or personal data from attachment content.
Remove sensitive data before attachment creation. GitHub controls access and
retention after upload. For more information, see
[Test attachments](attachments.md).

## Cache run state

Greenlight stores run state in the system temporary directory by default. Run
state contains failed test IDs and class durations from the previous run.

The next run uses this data to put failed classes first. It then puts the
remaining classes in longest-first order. This order reduces idle worker time
near the end of a run.

Configure a project state directory. Restore the state before the test step and
save it after the step.

For example, add this configuration to `greenlight.php`:

<!-- php-example {"mode":"display","reason":"Shows a configuration call after omitted calls."} -->
```php
return GreenlightConfig::create()
    // ...
    ->storage(fn ($storage) => $storage
        ->stateDirectory('build/greenlight-state'));
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
      - uses: actions/checkout@v7

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --no-progress --prefer-dist

      - name: Restore Greenlight run state
        uses: actions/cache/restore@v6
        with:
          path: build/greenlight-state/run-state*.json
          key: greenlight-run-state-v1-${{ runner.os }}-php-${{ matrix.php }}-${{ github.run_id }}-${{ github.run_attempt }}
          restore-keys: greenlight-run-state-v1-${{ runner.os }}-php-${{ matrix.php }}-

      - name: Run tests
        id: tests
        run: vendor/bin/greenlight run

      - name: Save Greenlight run state
        if: ${{ !cancelled() && steps.tests.outcome != 'skipped' }}
        uses: actions/cache/save@v6
        with:
          path: build/greenlight-state/run-state*.json
          key: greenlight-run-state-v1-${{ runner.os }}-php-${{ matrix.php }}-${{ github.run_id }}-${{ github.run_attempt }}
```

GitHub cache entries are immutable. The run ID and run attempt make each save
key unique. The restore prefix selects the most recent applicable entry.

The path pattern includes the default state file and state files for selected
suites.

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

## Merge coverage from shards

Each shard must write a Greenlight JSON coverage export. A later job can merge
these exports and apply gates to the complete suite.

First, make the export target configurable in `greenlight.php`:

<!-- php-example {"mode":"display","reason":"Shows environment selection inside an existing Greenlight configuration file."} -->
```php
$coverageOutput = getenv('GREENLIGHT_COVERAGE_OUTPUT')
    ?: 'build/coverage/coverage.json';

return GreenlightConfig::create()
    ->paths(['tests'])
    ->coverage(fn ($coverage) => $coverage
        ->include('src')
        ->requireDriver()
        ->export('json', $coverageOutput));
```

This workflow runs four shards. Each shard uses a unique artifact name and file
name. The merge job downloads all files into one directory.

```yaml
name: Sharded coverage

on:
  pull_request:
  push:
    branches: [main]

jobs:
  coverage-shard:
    name: Coverage shard ${{ matrix.shard }}/4
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        shard: [1, 2, 3, 4]
    steps:
      - uses: actions/checkout@v7

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: pcntl, sockets
          coverage: xdebug

      - name: Install dependencies
        run: composer install --no-interaction --no-progress --prefer-dist

      - name: Run coverage shard
        run: >-
          vendor/bin/greenlight run
          --shard=${{ matrix.shard }}/4
          --require-coverage-driver
        env:
          GREENLIGHT_COVERAGE_OUTPUT: build/coverage/shard-${{ matrix.shard }}.json

      - name: Upload shard coverage
        if: ${{ !cancelled() }}
        uses: actions/upload-artifact@v7
        with:
          name: coverage-shard-${{ matrix.shard }}
          path: build/coverage/shard-${{ matrix.shard }}.json
          if-no-files-found: error

  coverage-merge:
    name: Whole-suite coverage gate
    needs: coverage-shard
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: pcntl, sockets
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --no-progress --prefer-dist

      - name: Download shard coverage
        uses: actions/download-artifact@v8
        with:
          pattern: coverage-shard-*
          path: build/coverage/shards
          merge-multiple: true

      - name: Merge coverage and apply gates
        shell: bash
        run: |
          inputs=()
          for path in build/coverage/shards/shard-*.json; do
            inputs+=("--input=${path}")
          done

          vendor/bin/greenlight coverage:merge \
            "${inputs[@]}" \
            --export=json=build/coverage/coverage.json \
            --export=lcov=build/coverage/lcov.info \
            --minimum-coverage=90.00 \
            --maximum-uncovered-lines=100

      - name: Upload whole-suite coverage
        if: ${{ !cancelled() }}
        uses: actions/upload-artifact@v7
        with:
          name: whole-suite-coverage
          path: build/coverage
          if-no-files-found: error
```

The final upload runs after a passed or failed merge command. Greenlight writes
coverage exports before it checks the gates, so a failed gate keeps its
coverage evidence. The upload does not run after job cancellation.

GitHub-hosted matrix jobs use the same workspace path for one repository. Thus,
their absolute paths match.

For runners with different checkout roots, add one `--input-root` for each
input. Also add `--project-root` for the merge job checkout.

To compare with a saved baseline, omit the merge gates. Then run
`coverage:diff` with the merged JSON file as `--current`.
