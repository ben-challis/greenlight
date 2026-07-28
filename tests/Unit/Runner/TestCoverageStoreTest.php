<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\TestCoverageStore;
use JsonSchema\Validator;

final readonly class TestCoverageStoreTest
{
    public function __construct(private TempDirectory $temporaryDirectory) {}

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

        $first = new TestId('Example\SubjectTest', 'first');
        $second = new TestId('Example\SubjectTest', 'second', 'row one');
        $store = TestCoverageStore::open();
        $store->registerPlan(new ExecutionPlan([
            new PlanEntry($first, new TestMetadata($first->class, $first->method), $testFile),
            new PlanEntry($second, new TestMetadata($second->class, $second->method), $testFile),
        ]));
        $store->record($first, new CoverageMap([
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
        $schema = (object) ['$ref' => 'file://' . \dirname(__DIR__, 3) . '/resources/schema/test-coverage-jsonl-v1.schema.json'];

        $artifactLines = \file($target, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        foreach ($artifactLines === false ? [] : $artifactLines as $line) {
            $decoded = \json_decode($line, false, flags: \JSON_THROW_ON_ERROR);
            $validator = new Validator();
            $validator->validate($decoded, $schema);
            Expect::that($validator->isValid())->toBeTrue();

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

        $tests = \array_values(\array_filter(
            $records,
            static fn(array $record): bool => $record['type'] === 'test',
        ));
        $unattributed = \array_values(\array_filter(
            $records,
            static fn(array $record): bool => $record['type'] === 'unattributed',
        ));
        $coverage = \array_values(\array_filter(
            $records,
            static fn(array $record): bool => $record['type'] === 'coverage',
        ));

        Expect::that($tests)->toHaveCount(2);
        Expect::that($tests[1]['renderedId'])->toBe('Example\SubjectTest::second[row one]');
        Expect::that($coverage[0]['lines'])->toBe([2]);
        Expect::that($unattributed[0]['lines'])->toBe([6]);
    }
}
