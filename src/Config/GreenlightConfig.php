<?php

declare(strict_types=1);

namespace Greenlight\Config;

use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Core\Test\ResourceName;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\PluginDefinition;

/** Collects the configuration that `greenlight.php` returns. */
final class GreenlightConfig
{
    /**
     * @var non-empty-list<non-empty-string>
     */
    private array $paths = ['tests'];

    /**
     * @var array<non-empty-string, SuiteConfiguration>
     */
    private array $suites = [];

    private WorkerCount $workers;

    private ?CoverageBuilder $coverage = null;

    private ?WatchBuilder $watch = null;

    private ?ArtifactBuilder $artifacts = null;

    private ?StorageBuilder $storage = null;

    /**
     * @var list<PluginDefinition>
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
     * Sets the top-level test-discovery directories. Greenlight combines these
     * paths with the paths from all named suites.
     *
     * @param non-empty-list<non-empty-string> $tests
     *
     * @throws InvalidConfiguration
     */
    public function paths(array $tests): self
    {
        $this->paths = $this->validatePaths($tests);

        return $this;
    }

    /**
     * @param array<mixed> $tests
     *
     * @return non-empty-list<non-empty-string>
     *
     * @throws InvalidConfiguration
     */
    private function validatePaths(array $tests): array
    {
        if (!\array_is_list($tests)) {
            throw new InvalidConfiguration('Test paths must be a list.');
        }

        $validated = [];

        foreach ($tests as $path) {
            if (!\is_string($path)) {
                throw new InvalidConfiguration('Test paths must contain only strings.');
            }

            if ($path === '') {
                throw new InvalidConfiguration('Test paths cannot be empty strings.');
            }

            if (\str_contains($path, "\0")) {
                throw new InvalidConfiguration('Test paths cannot contain a null byte.');
            }

            $validated[] = $path;
        }

        if ($validated === []) {
            throw new InvalidConfiguration('paths() needs at least one directory.');
        }

        return $validated;
    }

    /**
     * Declares a named suite. Greenlight adds its paths to test discovery.
     *
     * The configurator receives a `SuiteBuilder`. It must add at least one path
     * with `in()`. Greenlight ignores its return value, which permits short
     * arrow functions.
     *
     * @param non-empty-string $name
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
        $this->suites[$name] = $builder->toConfiguration();

        return $this;
    }

    /**
     * Sets the number of worker processes.
     *
     * @param positive-int|'auto' $count
     *
     * @throws InvalidConfiguration
     */
    public function workers(int|string $count = 'auto'): self
    {
        $this->workers = $this->workerCount($count);

        return $this;
    }

    /**
     * Sets the maximum number of concurrent assignments that require the
     * named resource.
     *
     * A resource without an explicit resource limit has a limit of one.
     *
     * @param non-empty-string $name
     * @param positive-int $limit
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
        $builder = $this->coverage instanceof CoverageBuilder ? clone $this->coverage : new CoverageBuilder();
        $configurator($builder);
        $this->coverage = clone $builder;

        return $this;
    }

    /**
     * @param callable(WatchBuilder): mixed $configurator
     */
    public function watch(callable $configurator): self
    {
        $builder = $this->watch instanceof WatchBuilder ? clone $this->watch : new WatchBuilder();
        $configurator($builder);
        $this->watch = clone $builder;

        return $this;
    }

    /**
     * @param callable(ArtifactBuilder): mixed $configurator
     */
    public function artifacts(callable $configurator): self
    {
        $builder = $this->artifacts instanceof ArtifactBuilder ? clone $this->artifacts : new ArtifactBuilder();
        $configurator($builder);
        $this->artifacts = clone $builder;

        return $this;
    }

    /**
     * Sets directories for persistent state, caches, generated code, and
     * temporary run data. Multiple calls use the same builder.
     *
     * @param callable(StorageBuilder): mixed $configurator
     */
    public function storage(callable $configurator): self
    {
        $builder = $this->storage instanceof StorageBuilder ? clone $this->storage : new StorageBuilder();
        $configurator($builder);
        $this->storage = clone $builder;

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
     *
     * @param non-empty-string ...$patterns
     *
     * @throws InvalidConfiguration
     */
    public function ignoreDeprecationsMatching(string ...$patterns): self
    {
        $validated = [];

        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                throw new InvalidConfiguration('ignoreDeprecationsMatching() patterns cannot be empty.');
            }

            $validated[] = $pattern;
        }

        $this->ignoreDeprecations = [...$this->ignoreDeprecations, ...$validated];

        return $this;
    }

    /**
     * @param \Closure(): Plugin ...$plugins
     * @throws InvalidConfiguration
     */
    public function plugins(\Closure ...$plugins): self
    {
        $definitions = [];

        foreach ($plugins as $factory) {
            try {
                $definitions[] = PluginDefinition::fromFactory($factory);
            } catch (\InvalidArgumentException $error) {
                throw new InvalidConfiguration($error->getMessage(), $error->getCode(), previous: $error);
            }
        }

        $this->plugins = [...$this->plugins, ...$definitions];

        return $this;
    }

    public function failFast(bool $enabled = true): self
    {
        $this->failFast = $enabled;

        return $this;
    }

    /** If the seed is null, Greenlight selects and prints a seed when it resolves the command. */
    public function randomizeOrder(?int $seed = null): self
    {
        $this->randomizeOrder = true;
        $this->randomSeed = $seed;

        return $this;
    }

    /**
     * Uses a wider parameter type than the public contract. This gives callers
     * without static analysis a clear runtime error for invalid strings.
     * @throws InvalidConfiguration
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
     * @internal Greenlight converts the builder to its run configuration.
     * @throws InvalidConfiguration
     */
    public function build(): Configuration
    {
        return new Configuration(
            discovery: new DiscoveryConfiguration(
                paths: $this->paths,
                suites: \array_values($this->suites),
            ),
            workers: new WorkerConfiguration(
                count: $this->workers,
                resourceLimits: $this->resourceLimits,
            ),
            execution: new ExecutionConfiguration(
                plugins: $this->plugins,
                policy: new ResultPolicy(
                    $this->failOnDeprecation,
                    $this->failOnNotice,
                    $this->ignoreDeprecations,
                    $this->failOnRisky,
                ),
                stopAfterFailures: $this->failFast ? 1 : null,
                artifacts: $this->artifacts?->toConfiguration() ?? new ArtifactConfiguration(),
            ),
            order: new OrderConfiguration($this->randomizeOrder, $this->randomSeed),
            coverage: $this->coverage?->toConfiguration(),
            watch: $this->watch?->toConfiguration() ?? new WatchConfiguration(),
            storage: $this->storage?->toConfiguration() ?? new StorageConfiguration(),
        );
    }
}
