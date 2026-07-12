# Benchmarks

These benchmarks are generated and reproducible.

Run the full benchmark with:

```sh
php tools/benchmark.php --with-phpunit
```

The script generates the test suites, installs the comparison tools into a
throwaway project, and reports median wall-clock time over three runs.

## Setup

* Machine: Apple Silicon, 11 logical cores, macOS, local SSD
* PHP: 8.4.14 NTS, with default CLI opcache settings
* Greenlight: commit introducing this document
* PHPUnit: 13.2.3
* ParaTest: 7.23.0
* Parameters: `--scale=10 --workers=4 --runs=3`, which are the defaults

Wall-clock time includes process start, autoloading, discovery, execution, and
reporting. This matches the time a developer waits for during a normal test run.

## Results

| Shape                                         | Tests | Greenlight w=4 | Greenlight w=1 | PHPUnit | ParaTest p=4 |
| --------------------------------------------- | ----: | -------------: | -------------: | ------: | -----------: |
| many-fast: 400 classes with trivial bodies    |  2000 |         0.490s |         0.257s |  1.910s |       4.810s |
| few-slow: 8 classes, 25ms per test            |    32 |         0.529s |         1.064s |  1.326s |       0.840s |
| giant-dataset: 1 class, 1000 provider rows    |  1000 |         0.442s |         0.165s |  1.016s |       1.190s |
| mixed: fast tests, slow tests, and a data set |  1416 |         0.617s |         0.708s |  1.855s |       2.920s |

## Reading the results

Greenlight's fastest configuration is faster than PHPUnit's fastest
configuration on each generated shape in this run. The difference is mostly
runner overhead per test and per class, not just parallel execution.

Parallelism is not always faster. On trivial suites such as `many-fast` and
`giant-dataset`, Greenlight with four workers is slower than Greenlight with one
worker. Worker startup and socket communication cost more than the trivial test
bodies save.

Parallel execution helps once tests do enough work. In `few-slow`, four workers
reduce the run from 1.064s to 0.529s.

The `giant-dataset` shape is one class, so Greenlight's class-level scheduling
cannot split it across workers. Extra workers add overhead without adding
parallelism.

ParaTest shows the same overhead pattern more strongly on tiny work units. In
the `many-fast` shape, it is slower than plain PHPUnit because process-level
parallelism adds per-process overhead.

These are synthetic benchmarks from one machine. They are useful for comparing
runner overhead under known shapes, but they do not predict every real suite.
Suites dominated by I/O waits may benefit much more from parallel execution.

## Maintenance

CI runs a small benchmark to keep the harness working:

```sh
php tools/benchmark.php --shape=many-fast --scale=1 --runs=1
```

CI numbers are not published because shared runners are too noisy for stable
comparisons.

Update this document by rerunning the full benchmark on an idle machine whenever
the runner changes materially. Also update the tool versions above when the
comparison tools change.
