<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\CapturedOutput;
use Greenlight\Core\Result\Diagnostic;
use Greenlight\Core\Result\DiagnosticSeverity;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;

/**
 * The policy flips outcomes; everything else the result carries survives.
 */
final class ResultPolicyTest
{
    #[Test]
    public function aPolicyFlipPreservesTheRestOfTheResult(): void
    {
        $policy = new ResultPolicy(failOnDeprecation: true);
        $result = $policy->apply($this->passedWithDeprecation());

        Expect::that($result->outcome)->toBe(Outcome::Failed)
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

        Expect::that($policy->apply($before))->toBe($before);
    }

    private function passedWithDeprecation(): TestResult
    {
        return new TestResult(
            new TestId('App\ProbeTest', 'probes'),
            Outcome::Passed,
            durationSeconds: 0.25,
            memoryDeltaBytes: 0,
            attempts: 2,
            output: new CapturedOutput('', [
                new Diagnostic(DiagnosticSeverity::Deprecation, 'rusty api', '/src/a.php', 3),
            ]),
            expectations: 5,
        );
    }
}
