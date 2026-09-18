<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\State;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Cli\State\RunState;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\SkipTest;
use Greenlight\Tests\Support\FilesystemRestriction;

use function Greenlight\expect;

final readonly class RunStateTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function roundTripsFailureSetsIncludingEmpty(): void
    {
        $state = RunState::forFile($this->stateFile());

        expect($state->failedTests())->toBeNull();

        expect($state->record(['Acme\AlphaTest::one', 'Acme\BetaTest::two[label]']))->toBeTrue();
        expect($state->failedTests())->toBe(['Acme\AlphaTest::one', 'Acme\BetaTest::two[label]']);
        expect(RunState::forFile($this->stateFile())->failedTests())
            ->because('a later command MUST read the recorded failures from disk')
            ->toBe(['Acme\AlphaTest::one', 'Acme\BetaTest::two[label]']);

        expect($state->record([]))->toBeTrue();
        expect($state->failedTests())->toBe([]);
        expect(RunState::forFile($this->stateFile())->failedTests())
            ->because('a later successful run MUST clear the persisted failures')
            ->toBe([]);
    }

    #[Test]
    public function classDurationsRoundTripAndDefaultToEmpty(): void
    {
        $state = RunState::forFile($this->stateFile());

        expect($state->classSeconds())->toBe([]);

        expect($state->record([], ['Acme\AlphaTest' => 1.25, 'Acme\BetaTest' => 0.5]))->toBeTrue();
        expect($state->classSeconds())->toBe(['Acme\AlphaTest' => 1.25, 'Acme\BetaTest' => 0.5]);
        expect(RunState::forFile($this->stateFile())->classSeconds())
            ->because('a later command MUST read the recorded durations from disk')
            ->toBe(['Acme\AlphaTest' => 1.25, 'Acme\BetaTest' => 0.5]);
    }

    #[Test]
    public function invalidCachedClassDurationsAreIgnored(): void
    {
        $file = $this->stateFile();
        \file_put_contents($file, <<<'JSON'
            {
                "classSeconds": {
                    "Acme\\ValidTest": 1.25,
                    "Acme\\ZeroDurationTest": 0,
                    "Acme\\NegativeTest": -1,
                    "Acme\\OverflowTest": 1e400,
                    "Acme\\StringTest": "2.5",
                    "": 3
                }
            }
            JSON);

        expect(RunState::forFile($file)->classSeconds())
            ->because('cached durations MUST be finite, non-negative numbers for named classes')
            ->toBe([
                'Acme\ValidTest' => 1.25,
                'Acme\ZeroDurationTest' => 0.0,
            ]);
    }

    #[Test]
    public function corruptStateReadsAsAbsent(): void
    {
        $file = $this->stateFile();
        \file_put_contents($file, 'not json at all');

        expect(RunState::forFile($file)->failedTests())->because('corrupt state reads as absent')->toBeNull();

        \file_put_contents($file, '{"failed": "not a list"}');
        expect(RunState::forFile($file)->failedTests())->because('corrupt state reads as absent')->toBeNull();

        \file_put_contents($file, '{"failed": {"first": "Acme\\\\AlphaTest::one"}}');
        expect(RunState::forFile($file)->failedTests())->because('object-shaped state reads as absent')->toBeNull();
    }

    #[Test]
    public function malformedEntriesAreDiscardedWithoutLosingValidState(): void
    {
        $file = $this->stateFile();
        $state = RunState::forFile($file);

        \file_put_contents($file, \json_encode([
            'failed' => ['Acme\AlphaTest::one', '', 42, null],
            'classSeconds' => [
                'Acme\AlphaTest' => 1.25,
                'Acme\BetaTest' => 2,
                '' => 3,
                12 => 4,
                'Acme\InvalidTest' => 'slow',
            ],
        ], \JSON_THROW_ON_ERROR));

        expect($state->failedTests())
            ->because('invalid failed-test entries MUST NOT hide valid IDs')
            ->toBe(['Acme\AlphaTest::one']);

        expect($state->classSeconds())
            ->because('invalid duration entries MUST NOT hide valid timings')
            ->toBe([
                'Acme\AlphaTest' => 1.25,
                'Acme\BetaTest' => 2.0,
            ]);
    }

    #[Test]
    public function failuresAndDurationsComeFromOneStateSnapshot(): void
    {
        $file = $this->stateFile();
        \file_put_contents($file, \json_encode([
            'failed' => ['Acme\AlphaTest::one'],
            'classSeconds' => ['Acme\AlphaTest' => 1.25],
        ], \JSON_THROW_ON_ERROR));

        $state = RunState::forFile($file);

        expect($state->failedTests())->toBe(['Acme\AlphaTest::one']);

        \file_put_contents($file, \json_encode([
            'failed' => ['Acme\BetaTest::two'],
            'classSeconds' => ['Acme\BetaTest' => 2.5],
        ], \JSON_THROW_ON_ERROR));

        expect($state->classSeconds())
            ->because('one command MUST use failures and durations from the same state snapshot')
            ->toBe(['Acme\AlphaTest' => 1.25]);
    }

    #[Test]
    public function unreadableStateReadsAsAbsent(): void
    {
        $file = $this->stateFile();
        \file_put_contents($file, '{"failed":["Acme\\\\AlphaTest::one"]}');
        \chmod($file, 0o000);
        \clearstatcache(true, $file);

        if (\is_readable($file)) {
            throw new SkipTest('The filesystem does not enforce unreadable file permissions.');
        }

        expect(RunState::forFile($file)->failedTests())
            ->because('unreadable advisory state MUST behave as absent state')
            ->toBeNull();
    }

    #[Test]
    #[Isolated]
    public function aRestrictedStatePathReadsAsAbsentWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 4);
        $file = \dirname($root) . '/run-state.json';
        FilesystemRestriction::toProject($root);

        $failedTests = ErrorTrap::run(
            static fn() => RunState::forFile($file)->failedTests(),
            $warning,
        );

        expect($failedTests)
            ->because('restricted advisory state MUST behave as absent state')
            ->toBeNull();
        expect($warning)
            ->because('a restricted advisory state path MUST not leak engine diagnostics')
            ->toBeNull();
    }

    #[Test]
    public function recordWritesThroughATempFileAndLeavesNoneBehind(): void
    {
        $file = $this->stateFile();

        RunState::forFile($file)->record(['Acme\AlphaTest::one']);

        expect(RunState::forFile($file)->failedTests())->toBe(['Acme\AlphaTest::one']);
        expect(\glob($file . '.tmp-*'))->toBe([]);
    }

    #[Test]
    public function recordWhoseRenameFailsLeavesTheTargetUntouchedAndNoTempFile(): void
    {
        $file = $this->stateFile();

        // Put a nonempty directory at the target path. The temporary-file write
        // succeeds, but the final rename fails. This exercises the failure path
        // that MUST remove the temporary file.
        \mkdir($file);
        \file_put_contents($file . '/occupant.txt', 'keep');

        expect(RunState::forFile($file)->record(['Acme\AlphaTest::one']))->toBeFalse();

        expect(\is_dir($file))->toBeTrue();
        expect((string) \file_get_contents($file . '/occupant.txt'))->toBe('keep');
        expect(\glob($file . '.tmp-*'))->toBe([]);
    }

    #[Test]
    #[DataSet('nonFiniteDurations')]
    public function nonFiniteClassDurationsAreRejectedWithoutReplacingState(float $duration): void
    {
        $state = RunState::forFile($this->stateFile());

        expect($state->record(['Acme\AlphaTest::one']))->toBeTrue();
        expect($state->record(['Acme\BetaTest::two'], ['Acme\BetaTest' => $duration]))
            ->because('non-finite durations cannot be represented in the state JSON')
            ->toBeFalse();
        expect($state->failedTests())
            ->because('a failed encode does not replace the previous state')
            ->toBe(['Acme\AlphaTest::one']);
        expect(RunState::forFile($this->stateFile())->failedTests())
            ->because('a failed encode MUST preserve the previous file for later commands')
            ->toBe(['Acme\AlphaTest::one']);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteDurations(): iterable
    {
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'not a number' => [\NAN];
    }

    private function stateFile(): string
    {
        return $this->tempDirectory->path() . '/run-state.json';
    }
}
