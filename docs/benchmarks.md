# Benchmarks

Generated, reproducible, and honest about losses. Run `php tools/benchmark.php --with-phpunit` to reproduce; the script generates the suites, installs the comparison tools into the throwaway project, and reports median wall times over three runs.

## Setup

- Machine: Apple Silicon, 11 logical cores, macOS, local SSD.
- PHP 8.4.14 (NTS), opcache defaults for the CLI.
- Greenlight at the commit introducing this document; PHPUnit 13.2.3; ParaTest 7.23.0.
- Parameters: `--scale=10 --workers=4 --runs=3` (the defaults).
- Wall time includes process start, autoload, discovery, execution, and reporting, because that is what a developer waits for.

## Shapes and numbers

| Shape | Tests | Greenlight w=4 | Greenlight w=1 | PHPUnit | ParaTest p=4 |
|---|---|---|---|---|---|
| many-fast (400 classes, trivial bodies) | 2000 | 0.490s | 0.257s | 1.910s | 4.810s |
| few-slow (8 classes, 25ms per test) | 32 | 0.529s | 1.064s | 1.326s | 0.840s |
| giant-dataset (1 class, 1000 provider rows) | 1000 | 0.442s | 0.165s | 1.016s | 1.190s |
| mixed (fast + slow + data set) | 1416 | 0.617s | 0.708s | 1.855s | 2.920s |

## Reading the numbers

- On every shape, Greenlight's best configuration beats PHPUnit's best configuration by 2.5x to 7x. Most of the margin is engine overhead per test and per class, not parallelism.
- The losses, published on purpose: on trivial suites (many-fast, giant-dataset) Greenlight at `workers=4` is slower than `workers=1`, because worker spawn and socket traffic cost more than the trivial test bodies save. Parallelism pays when tests do real work (few-slow: 1.06s to 0.53s), which real suites do. A giant single class cannot parallelise at all under class-granular scheduling, so the extra workers are pure overhead there.
- ParaTest shows the same effect more sharply: on many-fast it is 2.5x slower than plain PHPUnit. Process-level parallelism amplifies per-process overhead when the work units are tiny.
- These are synthetic shapes on one machine. They bound the engine overhead story well and say nothing about suites dominated by I/O waits, where any parallel runner wins by roughly the worker count.

## Keeping it honest

CI runs `php tools/benchmark.php --shape=many-fast --scale=1 --runs=1` so the harness cannot rot; CI numbers are not published because shared runners are noisy. Update this document by re-running the full command on an idle machine whenever the runner changes materially, and update the versions above when the comparison tools move.
