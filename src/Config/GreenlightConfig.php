<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * The mutable fluent builder that greenlight.php files return. build()
 * produces the immutable Configuration.
 *
 * Defaults: paths ['tests'], workers 'auto' recycling after 500 tests or
 * above '256M', no suites, no coverage, no plugins, failFast off, declared
 * order with no seed.
 */
final class GreenlightConfig
{
    private const int DEFAULT_RECYCLE_AFTER_TESTS = 500;
    private const string DEFAULT_RECYCLE_ABOVE_MEMORY = '256M';

    /**
     * @var non-empty-list<non-empty-string>
     */
    private array $paths = ['tests'];

    /**
     * @var array<non-empty-string, SuiteBuilder>
     */
    private array $suites = [];

    private WorkerCount $workers;

    /**
     * @var positive-int
     */
    private int $recycleAfterTests = self::DEFAULT_RECYCLE_AFTER_TESTS;

    private string $recycleAboveMemory = self::DEFAULT_RECYCLE_ABOVE_MEMORY;

    private ?CoverageBuilder $coverage = null;

    /**
     * @var list<object>
     */
    private array $plugins = [];

    private bool $failFast = false;

    private bool $randomizeOrder = false;

    private ?int $randomSeed = null;

    private function __construct()
    {
        $this->workers = WorkerCount::auto();
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * Directories to discover tests in when no suite is selected.
     *
     * @param list<string> $tests
     *
     * @throws InvalidConfiguration
     */
    public function paths(array $tests): self
    {
        $validated = [];

        foreach ($tests as $path) {
            if ($path === '') {
                throw new InvalidConfiguration('Test paths cannot be empty strings.');
            }

            $validated[] = $path;
        }

        if ($validated === []) {
            throw new InvalidConfiguration('paths() needs at least one directory.');
        }

        $this->paths = $validated;

        return $this;
    }

    /**
     * Declares a named suite. The configurator receives a SuiteBuilder and
     * must give the suite at least one path via in(). The configurator's
     * return value is ignored, so terse arrow functions work.
     *
     * @param callable(SuiteBuilder): mixed $configurator
     *
     * @throws InvalidConfiguration
     */
    public function suite(string $name, callable $configurator): self
    {
        if ($name === '') {
            throw new InvalidConfiguration('Suite names cannot be empty.');
        }

        if (isset($this->suites[$name])) {
            throw new InvalidConfiguration(\sprintf('Suite "%s" is declared twice.', $name));
        }

        $builder = new SuiteBuilder($name);
        $configurator($builder);
        $this->suites[$name] = $builder;

        return $this;
    }

    /**
     * @param int|'auto' $count
     *
     * @throws InvalidConfiguration
     */
    public function workers(
        int|string $count = 'auto',
        int $recycleAfterTests = self::DEFAULT_RECYCLE_AFTER_TESTS,
        string $recycleAboveMemory = self::DEFAULT_RECYCLE_ABOVE_MEMORY,
    ): self {
        $this->workers = $this->workerCount($count);

        if ($recycleAfterTests < 1) {
            throw new InvalidConfiguration(\sprintf('recycleAfterTests must be at least 1, got %d.', $recycleAfterTests));
        }

        $this->recycleAfterTests = $recycleAfterTests;
        $this->recycleAboveMemory = $recycleAboveMemory;

        return $this;
    }

    /**
     * @param callable(CoverageBuilder): mixed $configurator
     */
    public function coverage(callable $configurator): self
    {
        $builder = $this->coverage ?? new CoverageBuilder();
        $configurator($builder);
        $this->coverage = $builder;

        return $this;
    }

    public function plugins(object ...$plugins): self
    {
        foreach ($plugins as $plugin) {
            $this->plugins[] = $plugin;
        }

        return $this;
    }

    public function failFast(bool $enabled = true): self
    {
        $this->failFast = $enabled;

        return $this;
    }

    /**
     * Enables randomized class order. A null seed means one is chosen and
     * printed at run time.
     */
    public function randomizeOrder(?int $seed = null): self
    {
        $this->randomizeOrder = true;
        $this->randomSeed = $seed;

        return $this;
    }

    /**
     * Deliberately wider than the public contract so callers without static
     * analysis still get a clear runtime error for bad strings.
     */
    private function workerCount(int|string $count): WorkerCount
    {
        if (\is_int($count)) {
            return WorkerCount::exactly($count);
        }

        if ($count === 'auto') {
            return WorkerCount::auto();
        }

        throw new InvalidConfiguration(\sprintf(
            'Worker count must be a positive integer or "auto", got "%s".',
            $count,
        ));
    }

    /**
     * @throws InvalidConfiguration
     */
    public function build(): Configuration
    {
        $suites = [];

        foreach ($this->suites as $builder) {
            $suites[] = $builder->toConfiguration();
        }

        return new Configuration(
            paths: $this->paths,
            suites: $suites,
            workers: $this->workers,
            recycleAfterTests: $this->recycleAfterTests,
            recycleAboveMemoryBytes: MemorySize::parseToBytes($this->recycleAboveMemory),
            coverage: $this->coverage?->toConfiguration(),
            plugins: $this->plugins,
            stopAfterFailures: $this->failFast ? 1 : null,
            randomizeOrder: $this->randomizeOrder,
            randomSeed: $this->randomSeed,
        );
    }
}
