<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Attribution;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Attribution\TestCoverageStore;
use Greenlight\Coverage\BranchCoverage;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\FunctionCoverage;
use Greenlight\Coverage\PathCoverage;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\TestDefinition;
use JsonSchema\Validator;

final readonly class TestCoverageStoreTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function writesACompleteVersionedArtifactWithoutKeepingTheRelationInMemory(): void
    {
        $root = $this->temporaryDirectory->path();
        $source = $root . '/src/Subject.php';
        $testFile = $root . '/tests/SubjectTest.php';
        \mkdir(\dirname($source), 0o777, true);
        \mkdir(\dirname($testFile), 0o777, true);
        \file_put_contents(
            $source,
            "<?php\nreturn 1;\n// @codeCoverageIgnoreStart\nreturn 2;\n// @codeCoverageIgnoreEnd\nreturn 3;\n",
        );
        \file_put_contents($testFile, "<?php\n");

        $first = new PlanEntry(new TestDefinition('Example\SubjectTest', 'first'), sourceFile: $testFile);
        $second = new PlanEntry(new TestDefinition('Example\SubjectTest', 'second'), 'row one', $testFile);
        $store = TestCoverageStore::open($root);
        $store->registerPlan(new ExecutionPlan([$first, $second]));
        $store->record($first->id, new CoverageMap([
            new FileCoverage($source, [2, 4], [6]),
        ]));

        $target = $root . '/coverage/test-map.jsonl';
        $store->write(
            $target,
            $root,
            'run-123',
            new CoverageMap([new FileCoverage($source, [2, 6], [])]),
        );

        $records = [];
        $types = [];
        $schema = (object) ['$ref' => 'file://' . \dirname(__DIR__, 4) . '/resources/schema/test-coverage-jsonl-v1.schema.json'];
        $artifactLines = \file($target, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        foreach ($artifactLines === false ? [] : $artifactLines as $line) {
            $decoded = \json_decode($line, false, flags: \JSON_THROW_ON_ERROR);
            $validator = new Validator();
            $validator->validate($decoded, $schema);
            Expect::that($validator->isValid())->because('each per-test coverage record MUST match version 1')->toBeTrue();

            $record = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);

            if (!\is_array($record)) {
                throw new \RuntimeException('Expected a JSON object coverage record.');
            }

            $records[] = $record;
            $types[] = $record['type'];
        }

        Expect::that($types)->toContain('meta')
            ->toContain('test')
            ->toContain('coverage')
            ->toContain('source')
            ->toContain('unattributed');

        $tests = \array_values(\array_filter($records, static fn(array $record): bool => $record['type'] === 'test'));
        $unattributed = \array_values(\array_filter($records, static fn(array $record): bool => $record['type'] === 'unattributed'));
        $coverage = \array_values(\array_filter($records, static fn(array $record): bool => $record['type'] === 'coverage'));

        Expect::that($tests)->toHaveCount(2);
        Expect::that($tests[1]['renderedId'])->toBe('Example\SubjectTest::second[row one]');
        Expect::that($coverage[0]['lines'])->toBe([2]);
        Expect::that($unattributed[0]['lines'])->toBe([6]);
    }

    #[Test]
    public function createsTheConfiguredSpoolDirectory(): void
    {
        $directory = $this->temporaryDirectory->path() . '/runtime/coverage';
        $store = TestCoverageStore::open($directory);

        Expect::that(\is_dir($directory))->toBeTrue();

        $store->close();
    }

    #[Test]
    public function versionTwoStreamsBranchAndPathAttribution(): void
    {
        $root = $this->temporaryDirectory->path();
        $source = $root . '/src/Decision.php';
        $testFile = $root . '/tests/DecisionTest.php';
        \mkdir(\dirname($source), 0o777, true);
        \mkdir(\dirname($testFile), 0o777, true);
        \file_put_contents($source, "<?php\nreturn true;\n");
        \file_put_contents($testFile, "<?php\n");

        $entry = new PlanEntry(new TestDefinition('Example\DecisionTest', 'coversTrue'), sourceFile: $testFile);
        $store = TestCoverageStore::open($root, true);
        $store->registerPlan(new ExecutionPlan([$entry]));
        $store->record($entry->id, $this->branchMap($source, true));
        $target = $root . '/coverage/branch-map.jsonl';
        $store->write($target, $root, 'run-branch', $this->branchMap($source, true));

        $lines = \file($target, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $records = [];
        $schema = (object) ['$ref' => 'file://' . \dirname(__DIR__, 4) . '/resources/schema/test-coverage-jsonl-v2.schema.json'];

        foreach ($lines === false ? [] : $lines as $line) {
            $decoded = \json_decode($line, false, flags: \JSON_THROW_ON_ERROR);
            $validator = new Validator();
            $validator->validate($decoded, $schema);
            Expect::that($validator->isValid())->because('each branch attribution record MUST match version 2')->toBeTrue();
            $records[] = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        }

        $types = \array_column($records, 'type');
        Expect::that($types)
            ->toContain('branch-coverage')
            ->toContain('path-coverage')
            ->toContain('source-branch')
            ->toContain('source-path');
    }

    private function branchMap(string $source, bool $covered): CoverageMap
    {
        return new CoverageMap([
            new FileCoverage($source, [2], [], [
                new FunctionCoverage('decide', [
                    new BranchCoverage(0, 2, 2, $covered),
                ], [
                    new PathCoverage([0], $covered),
                ]),
            ]),
        ], true);
    }
}
