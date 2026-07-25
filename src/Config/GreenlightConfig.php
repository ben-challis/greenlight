<?php

declare(strict_types=1);

namespace Greenlight\Config;

use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Core\Test\ResourceName;
use Greenlight\Plugin\Plugin;

/** Fluent configuration builder returned by greenlight.php. */
final class GreenlightConfig
{
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
     * @var positive-int|null
     */
    private ?int $recycleAfterTests = null;

    private string $recycleAboveMemory = self::DEFAULT_RECYCLE_ABOVE_MEMORY;

    private ?CoverageBuilder $coverage = null;

    private ?WatchBuilder $watch = null;

    private ?ArtifactBuilder $artifacts = null;

    /**
     * @var list<Plugin>
     */
    private array $plugins = [];

    private bool $failOnDeprecation = false;

    private bool $failOnNotice = false;

    private bool $failOnRisky = false;

    /**
     * @var list<non-empty-string>
     */
    private array $ignoreDeprecations = [];

    private bool $failFast = false;

    private bool $randomizeOrder = false;

    private ?int $randomSeed = null;

    /**
     * @var array<non-empty-string, positive-int>
     */
    private array $resourceLimits = [];

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
     * Test-count recycling is opt-in because each recycle boots a new worker.
     * Use it for state that memory-based recycling cannot bound.
     *
     * @param int|'auto' $count
     * @param int|null $recycleAfterTests null means workers are never
     *   recycled by test count
     *
     * @throws InvalidConfiguration
     */
    public function workers(
        int|string $count = 'auto',
        ?int $recycleAfterTests = null,
        string $recycleAboveMemory = self::DEFAULT_RECYCLE_ABOVE_MEMORY,
    ): self {
        $this->workers = $this->workerCount($count);

        if ($recycleAfterTests !== null && $recycleAfterTests < 1) {
            throw new InvalidConfiguration(\sprintf('recycleAfterTests must be at least 1, got %d.', $recycleAfterTests));
        }

        $this->recycleAfterTests = $recycleAfterTests;
        $this->recycleAboveMemory = $recycleAboveMemory;

        return $this;
    }

    /**
     * Limits simultaneous class assignments that require the named resource.
     *
     * A required resource with no configured limit defaults to one.
     *
     * @throws InvalidConfiguration
     */
    public function resourceLimit(string $name, int $limit = 1): self
    {
        try {
            ResourceName::assertValid($name);
        } catch (\InvalidArgumentException $error) {
            throw new InvalidConfiguration($error->getMessage(), $error->getCode(), previous: $error);
        }

        if ($limit < 1) {
            throw new InvalidConfiguration(\sprintf(
                'Resource "%s" must have a limit of at least 1, got %d.',
                $name,
                $limit,
            ));
        }

        if (\array_key_exists($name, $this->resourceLimits)) {
            throw new InvalidConfiguration(\sprintf('Resource limit "%s" is declared twice.', $name));
        }

        $this->resourceLimits[$name] = $limit;

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

    /**
     * @param callable(WatchBuilder): mixed $configurator
     */
    public function watch(callable $configurator): self
    {
        $builder = $this->watch ?? new WatchBuilder();
        $configurator($builder);
        $this->watch = $builder;

        return $this;
    }

    /**
     * @param callable(ArtifactBuilder): mixed $configurator
     */
    public function artifacts(callable $configurator): self
    {
        $builder = $this->artifacts ?? new ArtifactBuilder();
        $configurator($builder);
        $this->artifacts = $builder;

        return $this;
    }

    /**
     * Fails a passed test whose captured output contains a deprecation, with
     * the diagnostic as the failure detail. Exempt known dependency noise
     * with ignoreDeprecationsMatching().
     */
    public function failOnDeprecation(bool $enabled = true): self
    {
        $this->failOnDeprecation = $enabled;

        return $this;
    }

    public function failOnNotice(bool $enabled = true): self
    {
        $this->failOnNotice = $enabled;

        return $this;
    }

    /**
     * Fails a passed test that verified no expectations. Tests that
     * legitimately assert nothing opt out with #[NoExpectations].
     */
    public function failOnRisky(bool $enabled = true): self
    {
        $this->failOnRisky = $enabled;

        return $this;
    }

    /**
     * Exempts deprecation messages from failOnDeprecation(): patterns match
     * by case-insensitive substring, or against the whole message when they
     * contain "*" or "?". Repeatable; patterns accumulate.
     */
    public function ignoreDeprecationsMatching(string ...$patterns): self
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                throw new InvalidConfiguration('ignoreDeprecationsMatching() patterns cannot be empty.');
            }

            $this->ignoreDeprecations[] = $pattern;
        }

        return $this;
    }

    public function plugins(Plugin ...$plugins): self
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

    /** A null seed is generated and printed at run time. */
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
            watch: $this->watch?->toConfiguration() ?? new WatchConfiguration(),
            plugins: $this->plugins,
            policy: new ResultPolicy(
                $this->failOnDeprecation,
                $this->failOnNotice,
                $this->ignoreDeprecations,
                $this->failOnRisky,
            ),
            stopAfterFailures: $this->failFast ? 1 : null,
            randomizeOrder: $this->randomizeOrder,
            randomSeed: $this->randomSeed,
            artifacts: $this->artifacts?->toConfiguration() ?? new ArtifactConfiguration(),
            resourceLimits: $this->resourceLimits,
        );
    }
}
