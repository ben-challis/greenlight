# Benchmarks

The benchmark compares complete command run times for generated test suites.
It does not measure test-body time in isolation.

Save all samples from the full comparison:

```sh
php tools/benchmark.php --with-comparisons --format=json > benchmark.json
```

Run a Greenlight-only benchmark with the default table report:

```sh
php tools/benchmark.php
```

The `coverage-heavy` benchmark shape requires PCOV or Xdebug.

## Measurement controls

The harness applies these controls before and during each measurement:

* It generates equivalent Greenlight, PHPUnit, and Pest fixtures.
* A verification run creates one unique proof file for each test.
* The harness stops if a configuration does not execute all generated tests.
* Two warmups run before the measured samples.
* The harness discards all warmup times.
* Twelve sample rounds put each comparison configuration in each position twice.
* A seed makes the configuration order reproducible.
* A 100 ms pause separates command executions.
* One temporary project supplies all configurations for a benchmark shape.

The timer includes process start, autoload, discovery, report creation, and execution.
It measures the complete command that a user runs.

Output modes are not identical because the tools have different reporter
interfaces. Each tool creates its default noninteractive output.
Greenlight selects its plain reporter explicitly. The harness discards all output.

Each comparison configuration has a separate cache directory.
One tool cannot warm or change the cache of another tool.

Use the JSON report to inspect each exact command.

## Comparison tools

Use `--with-comparisons` to add these pinned tools:

* PHPUnit 13.3.0
* ParaTest 7.24.0
* Pest 5.1.1

The harness installs the tools in each temporary project.
Pest uses its closure-style test syntax.
The Pest comparison includes serial and parallel configurations.

The comparison tools run only the four common benchmark shapes.
The other shapes use Greenlight features that do not have equivalent configurations.

The common benchmark shapes are:

* `many-fast`: Many test files with short test bodies
* `few-slow`: A small number of test files with 25 ms test bodies
* `giant-dataset`: One test file with one large data set
* `mixed`: Fast tests, slow tests, and one large data set

The Greenlight-specific benchmark shapes are:

* `many-isolated`: Many isolated tests that each require a new worker
* `resource-constrained`: Work that one resource slot serializes
* `skewed-bootstrap`: Worker bootstrap delays that increase with the channel number
* `chatty-diagnostics`: Many notices that workers capture and send
* `coverage-heavy`: Assignments with large coverage maps

## Reports

The table report gives these robust statistics:

* First quartile (`q1`)
* Median
* Third quartile (`q3`)
* Relative median absolute deviation (`rMAD`)

The harness does not report the fastest sample as the result.
A low `rMAD` shows that samples stay close to the median.
A wide quartile interval or high `rMAD` identifies unstable measurements.

The JSON report also contains these items:

* Every raw sample in sample-round order for each configuration
* The exact command for each configuration
* All benchmark parameters
* The source revision
* The source-tree status
* The PHP version and binary path
* The platform description
* Loaded measurement extensions, such as Xdebug or `ddtrace`
* All resolved comparison package versions

Use `--seed` to reproduce the order.
Use `--warmups` and `--runs` to change the sample plan.
Use `--pause-ms` to change the pause between commands.

## Publication procedure

Use an idle machine with a stable power source and power mode.
Stop scheduled work and other variable workloads before the benchmark.
Record the processor model and power mode with the JSON report.

Choose the benchmark parameters before you inspect the results.
Commit all source changes before a publication run.
Publish all raw samples from the chosen run.
Do not select the fastest sample or the fastest run.

Do not publish results when `sourceTreeDirty` is `true`.

If the distribution is unstable, do not publish a performance claim.
Find the cause and execute the complete benchmark again.

Do not compare results from different benchmark shapes, parameters, machines,
PHP versions, source revisions, or comparison-tool versions.

Synthetic benchmark results do not predict all real test suites.
Use representative application suites before you make a general performance claim.

## Published results

The project does not currently publish benchmark numbers.
The previous numbers used three unbalanced samples and different output modes.
They do not meet the current publication procedure.

## Maintenance

CI runs a small benchmark to check the harness:

```sh
php tools/benchmark.php --shape=many-fast --scale=1 --workers=2 \
  --warmups=1 --runs=1 --pause-ms=0 --seed=20260821
```

The project does not publish CI numbers.
Shared runners do not give stable performance comparisons.

When the runner changes materially, execute the full benchmark on an idle machine.
Update the pinned comparison-tool constants and this page in the same change.
