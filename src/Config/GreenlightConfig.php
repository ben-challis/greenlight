<?php

declare(strict_types=1);

namespace Greenlight\Config;

use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Core\Test\ResourceName;
use Greenlight\Plugin\Plugin;

/** Collects the configuration that `greenlight.php` returns. */
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
     * Sets the test-discovery directories for runs without a selected suite.
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
     * Declares a named suite. The configurator receives a `SuiteBuilder`. The
     * configurator must add at least one path with `in()`. Greenlight ignores the
     * return value, which permits short arrow functions.
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
     * Greenlight replaces a worker after the specified number of tests only
     * when `$recycleAfterTests` has a value. Use this option for state that the
     * memory limit cannot control.
     *
     * @param int|'auto' $count
     * @param int|null $recycleAfterTests A null value disables test-count
     *   worker replacement.
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
     * Sets the maximum number of concurrent assignments that require the
     * named resource.
     *
     * A resource without an explicit resource limit has a limit of one.
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
     * Fails an otherwise passed test if captured output contains a
     * deprecation. The diagnostic becomes the failure detail. Use a regular
     * expression to exempt known dependency messages.
     *
     * @see self::ignoreDeprecationsMatching()
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
     * Fails an otherwise passed test if the test verifies no expectations.
     * Use `#[NoExpectations]` to exempt a test that intentionally verifies no
     * expectations.
     */
    public function failOnRisky(bool $enabled = true): self
    {
        $this->failOnRisky = $enabled;

        return $this;
    }

    /**
     * Exempts deprecation messages from `failOnDeprecation()`. A pattern matches
     * part of a message without case sensitivity. A pattern that contains "*"
     * or "?" matches the complete message. Multiple calls add patterns.
     */
    public function ignoreDeprecationsMatching(string ...$patterns): self
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                throw new InvalidConfiguration('ignoreDeprecationsMatching() patterns cannot be empty.');
            }
        }

        \array_push($this->ignoreDeprecations, ...\array_values($patterns));

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

    /** If the seed is null, Greenlight generates and prints a seed at run time. */
    public function randomizeOrder(?int $seed = null): self
    {
        $this->randomizeOrder = true;
        $this->randomSeed = $seed;

        return $this;
    }

    /**
     * Uses a wider parameter type than the public contract. This gives callers
     * without static analysis a clear runtime error for invalid strings.
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
