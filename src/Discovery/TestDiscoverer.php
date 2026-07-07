<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Core\Test\TestId;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Static test discovery. Scans directories for *Test.php files, resolves
 * each file's class by token parsing before autoloading it, reflects the
 * attributes into metadata, expands data-set providers, applies filters,
 * and produces a deterministic execution plan. No test code runs during
 * discovery; data-set providers are the single, budgeted exception.
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
        if ($providerTimeBudgetSeconds <= 0.0) {
            throw new \InvalidArgumentException('Provider time budget must be greater than zero seconds.');
        }

        $this->metadataFactory = new MetadataFactory();
        $this->dataSetExpander = new DataSetExpander();
    }

    /**
     * Default order is file path order; a seed shuffles the class order
     * deterministically. Methods always keep declaration order within a
     * class, and data sets keep provider order within a method.
     *
     * @param list<non-empty-string> $directories absolute paths to scan
     *
     * @throws DiscoveryError
     */
    public function discover(array $directories, ?Filter $filter = null, ?int $seed = null): ExecutionPlan
    {
        $filter ??= Filter::all();
        $entriesByClass = [];
        $classOrder = [];

        foreach ($this->testFiles($directories) as $file) {
            $entries = $this->entriesForFile($file, $filter);

            if ($entries === []) {
                continue;
            }

            $class = $entries[0]->id->class;
            $entriesByClass[$class] = $entries;
            $classOrder[] = $class;
        }

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
     * Fisher-Yates with a seeded engine, so the same seed always yields the
     * same class order regardless of global random state.
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
     * @param non-empty-string $file
     *
     * @return list<PlanEntry>
     */
    private function entriesForFile(string $file, Filter $filter): array
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
            if (!$filter->accepts($metadata->class, $metadata->method, $metadata->groups, $file)) {
                continue;
            }

            $rows = $this->dataSetExpander->rowsFor(
                $reflection,
                $metadata->method,
                $metadata->dataSetProvider,
                $this->providerTimeBudgetSeconds,
            );

            if ($rows === []) {
                $entries[] = new PlanEntry(new TestId($metadata->class, $metadata->method), $metadata);

                continue;
            }

            foreach (\array_keys($rows) as $key) {
                $entries[] = new PlanEntry(new TestId($metadata->class, $metadata->method, $key), $metadata);
            }
        }

        return \array_values(\array_filter(
            $entries,
            static fn(PlanEntry $entry): bool => $filter->acceptsId((string) $entry->id),
        ));
    }

    /**
     * Resolves the class declared in a file without executing the file:
     * token parsing yields the expected fully qualified name, autoloading
     * then loads exactly that class, and reflection confirms the class
     * really came from this file. Returns null when the file declares a
     * non-class type of the expected name, which is not a discovery error.
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
     * @param list<non-empty-string> $directories
     *
     * @return list<non-empty-string> sorted for a deterministic default order
     */
    private function testFiles(array $directories): array
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
