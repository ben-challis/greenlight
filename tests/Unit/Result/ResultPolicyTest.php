<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Diagnostic;
use Greenlight\Result\DiagnosticSeverity;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\OutcomeTransformation;
use Greenlight\Result\ResultPolicy;
use Greenlight\Result\SourceLocation;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\TestId;

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
        yield 'warning enforcement' => [new ResultPolicy(failOnWarning: true), false];
        yield 'risky-test enforcement' => [new ResultPolicy(failOnRisky: true), false];
    }

    #[Test]
    public function aPolicyFlipPreservesTheRestOfTheResult(): void
    {
        $earlierFailure = new FailureDetail(
            'An earlier policy accepted the result.',
            'failed',
            'passed',
            new SourceLocation('/tests/ProbeTest.php', 12),
        );
        $earlierTransformation = new OutcomeTransformation(
            'quarantine',
            Outcome::Failed,
            Outcome::Passed,
        );
        $original = new TestResult(
            id: new TestId('App\ProbeTest', 'probes', 'with deprecation'),
            outcome: Outcome::Passed,
            durationSeconds: 0.25,
            memoryDeltaBytes: 4096,
            attempts: 2,
            failures: [$earlierFailure],
            error: new ThrowableDetail(
                \RuntimeException::class,
                'The worker recovered.',
                '/tests/ProbeTest.php',
                18,
                ['App\ProbeTest->probes at /tests/ProbeTest.php:18'],
            ),
            skipReason: 'The dependency became available.',
            transformations: [$earlierTransformation],
            output: new CapturedOutput(
                'captured output',
                [new Diagnostic(DiagnosticSeverity::Deprecation, 'rusty api', '/src/a.php', 3)],
                stdoutTruncated: true,
                diagnosticsTruncated: true,
            ),
            risky: true,
            expectations: 5,
            attachments: [new Attachment(
                'deprecation.txt',
                AttachmentKind::Text,
                'text/plain',
                16,
                \str_repeat('a', 64),
                2,
                'artifacts/deprecation.txt',
                AttachmentRetention::Always,
            )],
        );
        $originalWire = $original->toWire();
        $policyFailure = new FailureDetail(
            'The deprecation policy changed this test from passed to failed: rusty api at /src/a.php:3',
        );
        $policyTransformation = new OutcomeTransformation(
            'fail-on-diagnostic policy',
            Outcome::Passed,
            Outcome::Failed,
        );
        $expected = \array_replace($originalWire, [
            'outcome' => Outcome::Failed->value,
            'failures' => [$earlierFailure->toWire(), $policyFailure->toWire()],
            'transformations' => [$earlierTransformation->toWire(), $policyTransformation->toWire()],
        ]);
        $policy = new ResultPolicy(failOnDeprecation: true);
        $result = $policy->apply($original);

        Expect::that($original->toWire())
            ->because('a policy flip MUST NOT change the original result')
            ->toBe($originalWire);
        Expect::that($result->toWire())
            ->because('a policy flip MUST preserve all result state that the policy does not change')
            ->toBe($expected);
    }

    #[Test]
    public function anIgnoredDeprecationLeavesTheResultUntouched(): void
    {
        $policy = new ResultPolicy(failOnDeprecation: true, ignoreDeprecations: ['rusty']);
        $before = $this->passedWithDeprecation();

        Expect::that($policy->apply($before))->because('an ignored deprecation leaves the result untouched')->toBe($before);
    }

    #[Test]
    public function anIgnoredDeprecationDoesNotSuppressAnActionableDeprecation(): void
    {
        $before = new TestResult(
            new TestId('App\ProbeTest', 'mixedDeprecations'),
            Outcome::Passed,
            durationSeconds: 0.1,
            memoryDeltaBytes: 0,
            output: new CapturedOutput('', [
                new Diagnostic(DiagnosticSeverity::Deprecation, 'vendor noise: legacy shim', '/vendor/noise.php', 2),
                new Diagnostic(DiagnosticSeverity::Deprecation, 'old api is deprecated', '/src/api.php', 7),
            ]),
        );
        $policy = new ResultPolicy(
            failOnDeprecation: true,
            ignoreDeprecations: ['vendor noise:*'],
        );

        $result = $policy->apply($before);

        Expect::that($result->outcome)
            ->because('each deprecation MUST be evaluated independently')
            ->toBe(Outcome::Failed);
        Expect::that($result->failures)
            ->toHaveCount(1);
        Expect::that($result->failures[0]->message)
            ->toBe(
                'The deprecation policy changed this test from passed to failed: '
                . 'old api is deprecated at /src/api.php:7',
            );
        Expect::that($result->transformations)
            ->toHaveCount(1);
        Expect::that($result->transformations[0]->transformedBy)
            ->toBe('fail-on-diagnostic policy');
    }

    #[Test]
    public function wildcardIgnoresAreCaseInsensitiveAndMatchTheWholeMessage(): void
    {
        $policy = new ResultPolicy(failOnDeprecation: true, ignoreDeprecations: ['LEGACY *']);
        $matching = $this->passedWithDeprecation('legacy api');
        $prefixed = $this->passedWithDeprecation('prefix legacy api');

        Expect::that($policy->apply($matching))
            ->because('a wildcard ignore MUST match without case sensitivity')
            ->toBe($matching);
        Expect::that($policy->apply($prefixed)->outcome)
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
            ->toBe(Outcome::Failed);
        Expect::that($result->failures)
            ->toHaveCount(1);
        Expect::that($result->failures[0]->message)
            ->toBe('The notice policy changed this test from passed to failed: a notice at /src/a.php:3');
    }

    #[Test]
    public function theWarningPolicyFailsAPassedTestWithAWarning(): void
    {
        $before = new TestResult(
            new TestId('App\ProbeTest', 'warns'),
            Outcome::Passed,
            durationSeconds: 0.1,
            memoryDeltaBytes: 0,
            output: new CapturedOutput('', [
                new Diagnostic(DiagnosticSeverity::Warning, 'a warning', '/src/a.php', 2),
            ]),
        );

        $result = new ResultPolicy(failOnWarning: true)->apply($before);

        Expect::that($result->outcome)
            ->because('the warning policy fails a passed test that captures a warning')
            ->toBe(Outcome::Failed);
        Expect::that($result->failures[0]->message)
            ->toBe('The warning policy changed this test from passed to failed: a warning at /src/a.php:2');
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
            ->toBe(Outcome::Failed);
        Expect::that($result->failures)
            ->toHaveCount(2);
        Expect::that($result->failures[0]->message)
            ->toBe(
                'The deprecation policy changed this test from passed to failed: old api at /src/old.php:4',
            );
        Expect::that($result->failures[1]->message)
            ->toBe(
                'The notice policy changed this test from passed to failed: notice too at /src/notice.php:6',
            );
        Expect::that($result->transformations)
            ->toHaveCount(1);
        Expect::that($result->transformations[0]->transformedBy)
            ->toBe('fail-on-diagnostic policy');
        Expect::that($result->expectations)
            ->toBe(5);
        Expect::that($result->attempts)
            ->toBe(2);
        Expect::that($result->durationSeconds)
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
            ->toBe(Outcome::Failed);
        Expect::that($result->transformations[0]->transformedBy)
            ->toBe('fail-on-risky policy');
        Expect::that($result->failures[0]->message)
            ->toBe('The fail-on-risky policy changed this test from passed to failed because it verified no expectations.');
    }

    #[Test]
    public function theWirePayloadPreservesSettingsAndDropsEmptyIgnorePatterns(): void
    {
        $restored = ResultPolicy::fromWire([
            'failOnDeprecation' => true,
            'failOnNotice' => true,
            'failOnWarning' => true,
            'ignoreDeprecations' => ['legacy *', ''],
            'failOnRisky' => true,
        ]);

        Expect::that($restored->toWire())
            ->because('the wire payload preserves policy settings and drops empty ignore patterns')
            ->toBe([
                'failOnDeprecation' => true,
                'failOnNotice' => true,
                'failOnWarning' => true,
                'ignoreDeprecations' => ['legacy *'],
                'failOnRisky' => true,
            ]);
    }

    #[Test]
    public function aCompatibleWirePayloadWithoutTheWarningPolicyUsesTheDefault(): void
    {
        $restored = ResultPolicy::fromWire([
            'failOnDeprecation' => false,
            'failOnNotice' => false,
            'ignoreDeprecations' => [],
            'failOnRisky' => false,
        ]);

        Expect::that($restored->failOnWarning)
            ->because('a compatible worker payload MUST disable the new warning policy')
            ->toBeFalse();
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
