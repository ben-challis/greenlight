<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\FileChange;
use Greenlight\Cli\Watch\ImpactedTestSelector;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestExclusions;
use Greenlight\Test\TestInclusions;
use Greenlight\Test\TestSelection;

final readonly class ImpactedTestSelectorTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function combinesPreviousFailuresWithTestsForCoveredChangedLines(): void
    {
        [$root, $testFile, $source, $artifact, $plan] = $this->fixture();
        $selection = new TestSelection(
            include: new TestInclusions(groups: ['fast'], idPatterns: ['*::runs']),
            exclude: new TestExclusions(groups: ['quarantined']),
            shard: [1, 2],
        );
        $selector = $this->selector($selection, $plan, $artifact, $root, $testFile, $source);
        $impact = $selector->select([
            new FileChange($source, true, true, "<?php\nold\n", "<?php\nnew\n"),
        ], ['Example\\FailedTest::runs'], 'run-one');

        Expect::that($impact->complete)
            ->because('a covered changed line has a reliable impacted selection')
            ->toBeFalse();
        Expect::that($impact->selection->include->exactIds)
            ->because('the impacted selection MUST include previous failures and line coverage')
            ->toBe(['Example\\FailedTest::runs', 'Example\\CoveredTest::runs']);
        Expect::that($impact->selection->include->idPatterns)
            ->because('the impacted exact IDs MUST replace broad ID patterns')
            ->toBe([]);
        Expect::that($impact->selection->include->groups)->toBe(['fast']);
        Expect::that($impact->selection->exclude->groups)->toBe(['quarantined']);
        Expect::that($impact->selection->shard)->toBe([1, 2]);
    }

    #[Test]
    public function mapsAChangedTestFileDirectlyToItsCurrentTests(): void
    {
        [$root, $testFile, $source, $artifact, $plan] = $this->fixture();
        $selector = $this->selector(new TestSelection(), $plan, $artifact, $root, $testFile, $source);
        $impact = $selector->select([
            new FileChange($testFile, true, true, "<?php\nold\n", "<?php\nnew\n"),
        ], [], 'run-one');

        Expect::that($impact->complete)->toBeFalse();
        Expect::that($impact->selection->include->exactIds)
            ->because('a changed test file MUST select each current test in that file')
            ->toBe(['Example\\CoveredTest::runs']);
    }

    #[Test]
    public function uncertainFileChangesRequireTheCompleteSelection(): void
    {
        [$root, $testFile, $source, $artifact, $plan] = $this->fixture();
        $selection = new TestSelection(include: new TestInclusions(groups: ['fast']));
        $selector = $this->selector($selection, $plan, $artifact, $root, $testFile, $source);
        $config = $root . '/greenlight.php';
        $outside = $root . '/bootstrap.php';

        $changes = [
            'deleted file' => new FileChange($source, true, false, '<?php'),
            'added source file' => new FileChange($source, false, true, after: '<?php'),
            'shifted source lines' => new FileChange($source, true, true, "<?php\n", "<?php\nnew\n"),
            'configuration file' => new FileChange($config, true, true),
            'outside configured roots' => new FileChange($outside, true, true),
        ];

        foreach ($changes as $name => $change) {
            $impact = $selector->select([$change], [], 'run-one');

            Expect::that($impact->complete)
                ->because($name . ' MUST require the complete selected plan')
                ->toBeTrue();
            Expect::that($impact->selection)->toBe($selection);
        }
    }

    #[Test]
    public function aDiscoveryErrorRequiresTheCompleteSelection(): void
    {
        [$root, $testFile, $source, $artifact] = $this->fixture();
        $selection = new TestSelection();
        $selector = new ImpactedTestSelector(
            $selection,
            static fn(): ExecutionPlan => throw DiscoveryError::directoryNotFound('/missing'),
            $artifact,
            [\dirname($testFile)],
            [\dirname($source)],
            $root,
            $root . '/greenlight.php',
        );
        $impact = $selector->select([
            new FileChange($testFile, true, true, '<?php', '<?php '),
        ], [], 'run-one');

        Expect::that($impact->complete)
            ->because('a discovery error MUST require the complete selected plan')
            ->toBeTrue();
        Expect::that($impact->diagnostic)->toContain('discovery');
    }

    #[Test]
    public function aMissingCoverageMapRequiresTheCompleteSelection(): void
    {
        [$root, $testFile, $source, $artifact, $plan] = $this->fixture();
        \unlink($artifact);
        $selection = new TestSelection();
        $selector = $this->selector($selection, $plan, $artifact, $root, $testFile, $source);
        $impact = $selector->select([
            new FileChange($source, true, true, "<?php\nold\n", "<?php\nnew\n"),
        ], [], 'run-one');

        Expect::that($impact->complete)
            ->because('a missing map MUST require the complete selected plan')
            ->toBeTrue();
        Expect::that($impact->selection)->toBe($selection);
        Expect::that($impact->diagnostic)->toContain('missing');
    }

    /**
     * @return array{non-empty-string, non-empty-string, non-empty-string, non-empty-string, ExecutionPlan}
     */
    private function fixture(): array
    {
        $root = $this->tempDirectory->subdirectory('impacted-selector-' . \bin2hex(\random_bytes(4)));
        $tests = $this->tempDirectory->subdirectory(\basename($root) . '/tests');
        $sources = $this->tempDirectory->subdirectory(\basename($root) . '/src');
        $testFile = $tests . '/CoveredTest.php';
        $failedFile = $tests . '/FailedTest.php';
        $source = $sources . '/Subject.php';
        $artifact = $root . '/coverage.jsonl';
        \file_put_contents($testFile, '<?php');
        \file_put_contents($failedFile, '<?php');
        \file_put_contents($source, "<?php\nold\n");
        \file_put_contents($root . '/greenlight.php', '<?php');
        $plan = new ExecutionPlan([
            new PlanEntry(new TestDefinition('Example\\CoveredTest', 'runs', ['fast']), sourceFile: $testFile),
            new PlanEntry(new TestDefinition('Example\\FailedTest', 'runs', ['fast']), sourceFile: $failedFile),
        ]);
        $records = [
            ['v' => 1, 'type' => 'meta', 'root' => $root, 'runId' => 'run-one', 'complete' => true],
            ['v' => 1, 'type' => 'test', 'test' => 0, 'renderedId' => 'Example\\CoveredTest::runs'],
            ['v' => 1, 'type' => 'test', 'test' => 1, 'renderedId' => 'Example\\FailedTest::runs'],
            ['v' => 1, 'type' => 'coverage', 'test' => 0, 'file' => $source, 'lines' => [2]],
            ['v' => 1, 'type' => 'source', 'file' => $source, 'covered' => true, 'lines' => [2]],
        ];
        $lines = \array_map(
            static fn(array $record): string => \json_encode($record, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            $records,
        );
        \file_put_contents($artifact, \implode("\n", $lines) . "\n");

        return [$root, $testFile, $source, $artifact, $plan];
    }

    private function selector(TestSelection $selection, ExecutionPlan $plan, string $artifact, string $root, string $testFile, string $source): ImpactedTestSelector
    {
        return new ImpactedTestSelector(
            $selection,
            static fn(): ExecutionPlan => $plan,
            $artifact,
            [\dirname($testFile)],
            [\dirname($source)],
            $root,
            $root . '/greenlight.php',
        );
    }
}
