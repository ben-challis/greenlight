<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\OutcomeTransformation;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;

final readonly class TestResultFailedByEvidenceTest
{
    #[Test]
    public function failureTransitionAppendsEarlierEvidenceAndProvenance(): void
    {
        $earlier = new FailureDetail('The initial assertion failed.');
        $later = new FailureDetail('The result policy failed.');
        $failed = new TestResult(
            new TestId('App\ProbeTest', 'isQuarantined'),
            Outcome::Failed,
            durationSeconds: 0.1,
            memoryDeltaBytes: 0,
            failures: [$earlier],
        );
        $passed = $failed->withOutcome(Outcome::Passed, 'quarantine-plugin');

        $result = $passed->failedBy('result-policy', [$later]);

        Expect::that($result->outcome)
            ->because('a later failure transition MUST retain earlier failure evidence')
            ->toBe(Outcome::Failed)
            ->and($result->failures)
            ->toBe([$earlier, $later])
            ->and(\array_map(
                static fn(OutcomeTransformation $transformation): array => [
                    $transformation->transformedBy,
                    $transformation->from,
                    $transformation->to,
                ],
                $result->transformations,
            ))
            ->toBe([
                ['quarantine-plugin', Outcome::Failed, Outcome::Passed],
                ['result-policy', Outcome::Passed, Outcome::Failed],
            ]);
    }
}
