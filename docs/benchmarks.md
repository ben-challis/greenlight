# Benchmarks

The benchmark script generates reproducible results.

Run the full benchmark with:

```sh
php tools/benchmark.php --with-phpunit
```

The script generates the test suites and installs the comparison tools in a
temporary project. It reports the median wall-clock time for three runs.

## Setup

* Machine: Apple Silicon, 11 logical cores, macOS, local SSD
* PHP: 8.4.14 NTS, with default CLI opcache settings
* Greenlight:
  [`305f833ab5d3`](https://github.com/ben-challis/greenlight/commit/305f833ab5d3fc8c046b04182a9d0989e6b072aa)
  (2026-07-12)
* PHPUnit: 13.2.3
* ParaTest: 7.23.0
* Parameters: `--scale=10 --workers=4 --runs=3`, which are the defaults

Wall-clock time includes process start, autoload, discovery, and execution.
Greenlight uses plain output, PHPUnit disables output, and ParaTest uses its
default output. The measurements include these output-mode differences.

These results do not enable per-test coverage. That opt-in mode starts and stops
pcov or Xdebug for every test and streams the resulting relation to disk, so it
has a different overhead profile. Measure it separately on the real suite that
will feed Infection; do not infer its cost from this table.

## Results

### `many-fast`

This shape has 400 classes with trivial bodies and 2,000 tests:

* Greenlight, 4 workers: 0.490s
* Greenlight, 1 worker: 0.257s
* PHPUnit: 1.910s
* ParaTest, 4 processes: 4.810s

### `few-slow`

This shape has 8 classes, 25ms for each test, and 32 tests:

* Greenlight, 4 workers: 0.529s
* Greenlight, 1 worker: 1.064s
* PHPUnit: 1.326s
* ParaTest, 4 processes: 0.840s

### `giant-dataset`

This shape has 1 class and 1,000 provider rows:

* Greenlight, 4 workers: 0.442s
* Greenlight, 1 worker: 0.165s
* PHPUnit: 1.016s
* ParaTest, 4 processes: 1.190s

### `mixed`

This shape combines fast tests, slow tests, and a data set. It has 1,416 tests:

* Greenlight, 4 workers: 0.617s
* Greenlight, 1 worker: 0.708s
* PHPUnit: 1.855s
* ParaTest, 4 processes: 2.920s

## Results analysis

In this run, Greenlight's fastest configuration is faster on each generated
shape than PHPUnit's fastest configuration. Runner overhead for each test and
class causes most of the difference. Parallelism is not the only cause.

Parallelism is not always faster. On trivial suites such as `many-fast` and
`giant-dataset`, four Greenlight workers are slower than one worker. Worker
startup and socket communication cost more time than the trivial test bodies
save.

Parallel execution helps once tests do enough work. In `few-slow`, four workers
reduce the run from 1.064s to 0.529s.

The `giant-dataset` shape is one class. Thus, the Greenlight class-level
schedule cannot split it across workers. Extra workers add overhead but do not
add parallelism.

A similar overhead pattern has a larger effect on small ParaTest work units. In the
`many-fast` shape, ParaTest is slower than plain PHPUnit. Process-level
parallelism adds overhead for each process.

These synthetic benchmarks are from one machine. They help compare runner
overhead for known shapes, but they do not predict every real suite. Suites with
large I/O waits can have a much larger benefit from parallel execution.

## Maintenance

CI runs a small benchmark to check the harness:

```sh
php tools/benchmark.php --shape=many-fast --scale=1 --runs=1
```

The project does not publish CI numbers because shared runners do not give
stable comparisons.

When the runner changes materially, run the full benchmark on an idle machine.
Record the Greenlight commit and run date with the results. The
`tools/benchmark.php` file pins comparison-tool versions. Update the constants
and this page in the same change.
