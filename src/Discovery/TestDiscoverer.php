<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Core\Test\TestId;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Discovery does not invoke test methods. It invokes only user callables that
 * are data providers.
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
    public function discover(array $directories, ?Filter $filter = null, ?int $seed = null, ?DiscoveryCache $cache = null): ExecutionPlan
    {
        $filter ??= Filter::all();
        $entriesByClass = [];
        $classOrder = [];

        foreach ($this->testFiles($directories) as $file) {
            $unfiltered = $cache?->lookup($file);

            if ($unfiltered === null) {
                $unfiltered = $this->entriesForFile($file);
                $cache?->store($file, $unfiltered);
            }

            $entries = $this->filtered($unfiltered, $filter, $file);

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
     * Uses Fisher-Yates with a seeded engine. Thus, the same seed always
     * produces the same class order without dependence on global random state.
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
     * Returns all entries that a file declares. The result does not contain
     * filter decisions. Thus, the cache can use the result with all run
     * filters.
     *
     * @param non-empty-string $file
     *
     * @return list<PlanEntry>
     */
    private function entriesForFile(string $file): array
    {
        $class = $this->resolveClass($file);

        if ($class === null) {
            return [];
        }

        $reflection = new \ReflectionClass($class);

        if ($reflection->isAbstract()) {
            return [];
        }

        $entries = [];

        foreach ($this->metadataFactory->forClass($reflection) as $metadata) {
            $rows = $this->dataSetExpander->rowsFor(
                $reflection,
                $metadata->method,
                $metadata->dataSetProvider,
                $this->providerTimeBudgetSeconds,
                $metadata->dataSetProviderClass,
            );

            if ($rows === []) {
                $entries[] = new PlanEntry(new TestId($metadata->class, $metadata->method), $metadata);

                continue;
            }

            foreach (\array_keys($rows) as $key) {
                $entries[] = new PlanEntry(new TestId($metadata->class, $metadata->method, (string) $key), $metadata);
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
    private function filtered(array $entries, Filter $filter, string $file): array
    {
        return \array_values(\array_filter(
            $entries,
            static fn(PlanEntry $entry): bool => $filter->accepts(
                $entry->metadata->class,
                $entry->metadata->method,
                $entry->metadata->groups,
                $file,
            ) && $filter->acceptsId((string) $entry->id),
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

            if ($actualFile === false || \realpath($actualFile) !== \realpath($file)) {
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
            $real = \realpath($directory);

            if ($real === false || !\is_dir($real)) {
                throw DiscoveryError::directoryNotFound($directory);
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                    continue;
                }

                $path = $item->getPathname();

                if ($path !== '' && \str_ends_with($item->getFilename(), 'Test.php')) {
                    $files[$path] = $path;
                }
            }
        }

        $paths = \array_values($files);
        \sort($paths, \SORT_STRING);

        return $paths;
    }
}
