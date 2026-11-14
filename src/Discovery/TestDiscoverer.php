<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Test\DataSet\DataSetError;
use Greenlight\Test\DataSet\DataSetExpander;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestSelection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Builds an execution plan from test metadata and data providers.
 * Discovery loads test classes through registered autoloaders. It does not
 * invoke test methods or lifecycle hooks.
 *
 * @internal
 */
final readonly class TestDiscoverer
{
    private MetadataFactory $metadataFactory;

    private DataSetExpander $dataSetExpander;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private float $providerTimeBudgetSeconds = 5.0,
    ) {
        if (!\is_finite($providerTimeBudgetSeconds) || $providerTimeBudgetSeconds <= 0.0) {
            throw new \InvalidArgumentException('Provider time budget seconds must be finite and greater than zero.');
        }

        $this->metadataFactory = new MetadataFactory();
        $this->dataSetExpander = new DataSetExpander();
    }

    /**
     * The default order is file path order. A seed changes the class order in
     * a deterministic way. Methods remain in declaration order in a class.
     * Data sets remain in provider order in a method.
     *
     * @param list<non-empty-string> $directories absolute paths to scan
     *
     * @throws DiscoveryError
     */
    public function discover(array $directories, ?TestSelection $selection = null, ?int $seed = null, ?DiscoveryCache $cache = null): ExecutionPlan
    {
        $selection ??= new TestSelection();
        $entriesByClass = [];
        $classOrder = [];

        foreach ($this->testFiles($directories) as $file) {
            $definitions = $cache?->lookup($file);
            $parsed = $definitions === null;
            $class = null;

            $definitions ??= $this->definitionsForFile($file, $class);

            try {
                $unfiltered = $this->expanded($definitions, $file);
            } catch (DataSetError $error) {
                throw DiscoveryError::invalidDataSet($error);
            }

            // Expansion loads provider classes. The cache can then track their files.
            if ($parsed) {
                $cache?->store($file, $definitions, $class);
            }

            $entries = $this->filtered($unfiltered, $selection, $file);

            if ($entries === []) {
                continue;
            }

            $class = $entries[0]->id->class;
            $entriesByClass[$class] = $entries;
            $classOrder[] = $class;
        }

        $cache?->persist();

        if ($seed !== null) {
            $classOrder = $this->shuffled($classOrder, $seed);
        }

        $flat = [];

        foreach ($classOrder as $class) {
            foreach ($entriesByClass[$class] as $entry) {
                $flat[] = $entry;
            }
        }

        return new ExecutionPlan($flat, $seed);
    }

    /**
     * Uses Fisher-Yates with a seeded engine. The same input order and seed
     * produce the same class order without dependence on global random state.
     *
     * @param list<non-empty-string> $classes
     *
     * @return list<non-empty-string>
     */
    private function shuffled(array $classes, int $seed): array
    {
        $randomizer = new Randomizer(new Mt19937($seed));

        for ($i = \count($classes) - 1; $i > 0; --$i) {
            $j = $randomizer->getInt(0, $i);
            [$classes[$i], $classes[$j]] = [$classes[$j], $classes[$i]];
        }

        return \array_values($classes);
    }

    /**
     * Returns one entry without a data-set key for each test definition in a
     * file. The result does not contain filter decisions or data sets. Thus,
     * the cache can use the result with all run filters, and provider output
     * stays current.
     *
     * @param non-empty-string $file
     *
     * @param-out class-string|null $class
     *
     * @return list<PlanEntry>
     * @throws DiscoveryError
     */
    private function definitionsForFile(string $file, ?string &$class): array
    {
        $class = $this->resolveClass($file);

        if ($class === null) {
            return [];
        }

        $reflection = new \ReflectionClass($class);

        if ($reflection->isAbstract()) {
            return [];
        }

        return \array_map(
            static fn(TestDefinition $definition): PlanEntry => new PlanEntry($definition),
            $this->metadataFactory->forClass($reflection),
        );
    }

    /**
     * Replaces each definition entry with one entry for each data set.
     *
     * @param list<PlanEntry> $definitions
     * @param non-empty-string $file
     *
     * @return list<PlanEntry>
     * @throws DataSetError
     * @throws DiscoveryError
     */
    private function expanded(array $definitions, string $file): array
    {
        $entries = [];
        $reflections = [];

        foreach ($definitions as $entry) {
            $definition = $entry->definition;

            if (!isset($reflections[$definition->class])) {
                if (!\class_exists($definition->class)) {
                    throw DiscoveryError::classNotAutoloadable($file, $definition->class);
                }

                $reflections[$definition->class] = new \ReflectionClass($definition->class);
            }

            $reflection = $reflections[$definition->class];
            $rows = $this->dataSetExpander->rowsFor(
                $reflection,
                $definition->method,
                $definition->dataProvider->method,
                $this->providerTimeBudgetSeconds,
                $definition->dataProvider->class,
            );

            if ($rows === []) {
                $entries[] = new PlanEntry($definition);

                continue;
            }

            foreach (\array_keys($rows) as $key) {
                $entries[] = new PlanEntry($definition, (string) $key);
            }
        }

        return $entries;
    }

    /**
     * @param list<PlanEntry> $entries
     * @param non-empty-string $file
     *
     * @return list<PlanEntry>
     */
    private function filtered(array $entries, TestSelection $selection, string $file): array
    {
        return \array_values(\array_filter(
            $entries,
            static fn(PlanEntry $entry): bool => $selection->accepts(
                $entry->definition->class,
                $entry->definition->method,
                $entry->definition->groups,
                $file,
            ) && $selection->acceptsId((string) $entry->id),
        ));
    }

    /**
     * Resolves the class in a file without direct execution of the file.
     *
     * The token parser supplies the expected fully qualified name. The
     * autoloader then loads only that class. Reflection confirms that the
     * class originates in this file.
     *
     * Returns null if the file declares a non-class type with the expected
     * name. This condition is not a discovery error.
     *
     * @param non-empty-string $file
     *
     * @return class-string|null
     * @throws DiscoveryError
     */
    private function resolveClass(string $file): ?string
    {
        $declarations = ClassFileParser::declarationsIn($file);
        $expected = \basename($file, '.php');

        foreach ($declarations as $declaration) {
            if ($declaration->shortName !== $expected) {
                continue;
            }

            if ($declaration->kind !== 'class') {
                return null;
            }

            $fqcn = $declaration->fqcn();

            if (!\class_exists($fqcn)) {
                throw DiscoveryError::classNotAutoloadable($file, $fqcn);
            }

            $actualFile = new \ReflectionClass($fqcn)->getFileName();
            [$resolvedActualFile, $resolvedExpectedFile] = ErrorTrap::run(
                static fn() => [
                    $actualFile === false ? false : \realpath($actualFile),
                    \realpath($file),
                ],
            );

            if ($resolvedActualFile === false
                || $resolvedExpectedFile === false
                || $resolvedActualFile !== $resolvedExpectedFile
            ) {
                throw DiscoveryError::classLoadedFromOtherFile($file, $fqcn, $actualFile === false ? '(no file)' : $actualFile);
            }

            return $fqcn;
        }

        if ($declarations === []) {
            throw DiscoveryError::noClassInFile($file);
        }

        throw DiscoveryError::classNameMismatch($file, $declarations[0]->shortName, $expected);
    }

    /**
     * Returns the files that a discover() call examines.
     *
     * Callers can compare path filters to the paths that the filter matches.
     *
     * @param list<non-empty-string> $directories absolute paths to scan
     *
     * @return list<non-empty-string> sorted for a deterministic default order
     *
     * @throws DiscoveryError
     */
    public function testFiles(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $real = ErrorTrap::run(
                static fn() => \realpath($directory),
                wrap: static fn(\Throwable $error): DiscoveryError =>
                    DiscoveryError::directoryNotFound($directory, $error),
            );

            if ($real === false || !\is_dir($real)) {
                throw DiscoveryError::directoryNotFound($directory);
            }

            $this->collectTestFiles($real, [$real => true], $files);
        }

        $paths = \array_values($files);
        \sort($paths, \SORT_STRING);

        return $paths;
    }

    /**
     * Walks a directory and follows directory symlinks. A directory whose real
     * path is also the real path of an ancestor makes a cycle, so the walk
     * does not enter it. Each real test file occurs once. If a file has more
     * than one path, the walk keeps the path that sorts first.
     *
     * @param array<string, true> $ancestors real paths of this directory and its ancestors
     * @param array<string, non-empty-string> $files real path to test file path
     *
     * @param-out array<string, non-empty-string> $files
     *
     * @throws DiscoveryError
     */
    private function collectTestFiles(string $directory, array $ancestors, array &$files): void
    {
        $names = ErrorTrap::run(static fn() => \scandir($directory, \SCANDIR_SORT_ASCENDING), $warning);

        if ($names === false) {
            throw DiscoveryError::unreadableDirectory($directory, $warning);
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory . '/' . $name;
            [$isDirectory, $isFile, $real] = ErrorTrap::run(static fn() => [\is_dir($path), \is_file($path), \realpath($path)]);

            if ($real === false) {
                continue;
            }

            if ($isDirectory) {
                if (!isset($ancestors[$real])) {
                    $this->collectTestFiles($path, [...$ancestors, $real => true], $files);
                }

                continue;
            }

            if ($isFile && \str_ends_with($name, 'Test.php') && (!isset($files[$real]) || \strcmp($path, $files[$real]) < 0)) {
                $files[$real] = $path;
            }
        }
    }
}
