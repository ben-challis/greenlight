<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Result\CapturedOutput;
use Greenlight\Core\Result\Diagnostic;
use Greenlight\Core\Result\DiagnosticSeverity;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;

final class ResultPolicyTest
{
    #[Test]
    #[DataSet('policyActivity')]
    public function onlyBehaviorChangingFlagsMakeThePolicyActive(ResultPolicy $policy, bool $noOp): void
    {
        Expect::that($policy->isNoOp())
            ->because('worker dispatch MUST omit only behaviorally inactive result policies')
            ->toBe($noOp);
    }

    /**
     * @return iterable<string, array{ResultPolicy, bool}>
     */
    public static function policyActivity(): iterable
    {
        yield 'default policy' => [new ResultPolicy(), true];
        yield 'ignore patterns without enforcement' => [
            new ResultPolicy(ignoreDeprecations: ['legacy *']),
            true,
        ];
        yield 'deprecation enforcement' => [new ResultPolicy(failOnDeprecation: true), false];
        yield 'notice enforcement' => [new ResultPolicy(failOnNotice: true), false];
        yield 'risky-test enforcement' => [new ResultPolicy(failOnRisky: true), false];
    }

    #[Test]
    public function aPolicyFlipPreservesTheRestOfTheResult(): void
    {
        $policy = new ResultPolicy(failOnDeprecation: true);
        $result = $policy->apply($this->passedWithDeprecation());

        Expect::that($result->outcome)->because('a policy flip preserves the rest of the result')->toBe(Outcome::Failed)
            ->and($result->expectations)->toBe(5)
            ->and($result->attempts)->toBe(2)
            ->and($result->durationSeconds)->toBe(0.25)
            ->and($result->transformations)->toHaveCount(1)
            ->and($result->transformations[0]->transformedBy)->toBe('fail-on-diagnostic policy')
            ->and($result->failures)->toHaveCount(1)
            ->and($result->failures[0]->message)->toContain('rusty api');
    }

    #[Test]
    public function anIgnoredDeprecationLeavesTheResultUntouched(): void
    {
        $policy = new ResultPolicy(failOnDeprecation: true, ignoreDeprecations: ['rusty']);
        $before = $this->passedWithDeprecation();

        Expect::that($policy->apply($before))->because('an ignored deprecation leaves the result untouched')->toBe($before);
    }

    #[Test]
    public function wildcardIgnoresAreCaseInsensitiveAndMatchTheWholeMessage(): void
    {
        $policy = new ResultPolicy(failOnDeprecation: true, ignoreDeprecations: ['LEGACY *']);
        $matching = $this->passedWithDeprecation('legacy api');
        $prefixed = $this->passedWithDeprecation('prefix legacy api');

        Expect::that($policy->apply($matching))
            ->because('a wildcard ignore MUST match without case sensitivity')
            ->toBe($matching)
            ->and($policy->apply($prefixed)->outcome)
            ->because('a wildcard ignore MUST match the complete deprecation message')
            ->toBe(Outcome::Failed);
    }

    #[Test]
    public function aNonPassingResultIsNeverRewritten(): void
    {
        $before = new TestResult(
            new TestId('App\ProbeTest', 'fails'),
            Outcome::Failed,
            durationSeconds: 0.1,
            memoryDeltaBytes: 0,
            output: new CapturedOutput('', [
                new Diagnostic(DiagnosticSeverity::Notice, 'a notice', '/src/a.php', 3),
            ]),
            risky: true,
        );
        $policy = new ResultPolicy(failOnDeprecation: true, failOnNotice: true, failOnRisky: true);

        Expect::that($policy->apply($before))
            ->because('a result policy changes only passed tests')
            ->toBe($before);
    }

    #[Test]
    public function theNoticePolicyDoesNotMakeWarningsFatal(): void
    {
        $before = new TestResult(
            new TestId('App\ProbeTest', 'notices'),
            Outcome::Passed,
            durationSeconds: 0.1,
            memoryDeltaBytes: 0,
            output: new CapturedOutput('', [
                new Diagnostic(DiagnosticSeverity::Warning, 'a warning', '/src/a.php', 2),
                new Diagnostic(DiagnosticSeverity::Notice, 'a notice', '/src/a.php', 3),
            ]),
        );

        $result = new ResultPolicy(failOnNotice: true)->apply($before);

        Expect::that($result->outcome)
            ->because('the notice policy does not make warnings fatal')
            ->toBe(Outcome::Failed)
            ->and($result->failures)
            ->toHaveCount(1)
            ->and($result->failures[0]->message)
            ->toBe('The notice policy changed this test from passed to failed: a notice at /src/a.php:3');
    }

    #[Test]
    public function diagnosticFailuresAggregateBeforeTheRiskyPolicy(): void
    {
        $before = new TestResult(
            new TestId('App\ProbeTest', 'multipleDiagnostics'),
            Outcome::Passed,
            durationSeconds: 0.25,
            memoryDeltaBytes: 0,
            attempts: 2,
            output: new CapturedOutput('', [
                new Diagnostic(DiagnosticSeverity::Deprecation, 'old api', '/src/old.php', 4),
                new Diagnostic(DiagnosticSeverity::Warning, 'warning only', '/src/warning.php', 5),
                new Diagnostic(DiagnosticSeverity::Notice, 'notice too', '/src/notice.php', 6),
            ]),
            risky: true,
            expectations: 5,
        );
        $policy = new ResultPolicy(
            failOnDeprecation: true,
            failOnNotice: true,
            failOnRisky: true,
        );

        $result = $policy->apply($before);

        Expect::that($result->outcome)
            ->because('diagnostic failures aggregate before the risky policy')
            ->toBe(Outcome::Failed)
            ->and($result->failures)
            ->toHaveCount(2)
            ->and($result->failures[0]->message)
            ->toBe(
                'The deprecation policy changed this test from passed to failed: old api at /src/old.php:4',
            )
            ->and($result->failures[1]->message)
            ->toBe(
                'The notice policy changed this test from passed to failed: notice too at /src/notice.php:6',
            )
            ->and($result->transformations)
            ->toHaveCount(1)
            ->and($result->transformations[0]->transformedBy)
            ->toBe('fail-on-diagnostic policy')
            ->and($result->expectations)
            ->toBe(5)
            ->and($result->attempts)
            ->toBe(2)
            ->and($result->durationSeconds)
            ->toBe(0.25);
    }

    #[Test]
    public function theRiskyPolicyFailsAPassedResultWithoutExpectations(): void
    {
        $before = new TestResult(
            new TestId('App\ProbeTest', 'risky'),
            Outcome::Passed,
            durationSeconds: 0.1,
            memoryDeltaBytes: 0,
            risky: true,
        );

        $result = new ResultPolicy(failOnRisky: true)->apply($before);

        Expect::that($result->outcome)
            ->because('the risky policy fails a passed result without expectations')
            ->toBe(Outcome::Failed)
            ->and($result->transformations[0]->transformedBy)
            ->toBe('fail-on-risky policy')
            ->and($result->failures[0]->message)
            ->toBe('The fail-on-risky policy changed this test from passed to failed because it verified no expectations.');
    }

    #[Test]
    public function theWirePayloadPreservesSettingsAndDropsEmptyIgnorePatterns(): void
    {
        $restored = ResultPolicy::fromWire([
            'failOnDeprecation' => true,
            'failOnNotice' => true,
            'ignoreDeprecations' => ['legacy *', ''],
            'failOnRisky' => true,
        ]);

        Expect::that($restored->toWire())
            ->because('the wire payload preserves policy settings and drops empty ignore patterns')
            ->toBe([
                'failOnDeprecation' => true,
                'failOnNotice' => true,
                'ignoreDeprecations' => ['legacy *'],
                'failOnRisky' => true,
            ]);
    }

    private function passedWithDeprecation(string $message = 'rusty api'): TestResult
    {
        return new TestResult(
            new TestId('App\ProbeTest', 'probes'),
            Outcome::Passed,
            durationSeconds: 0.25,
            memoryDeltaBytes: 0,
            attempts: 2,
            output: new CapturedOutput('', [
                new Diagnostic(DiagnosticSeverity::Deprecation, $message, '/src/a.php', 3),
            ]),
            expectations: 5,
        );
    }
}
