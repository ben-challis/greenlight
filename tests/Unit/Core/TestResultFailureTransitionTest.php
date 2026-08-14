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

final readonly class TestResultFailureTransitionTest
{
    #[Test]
    public function failedTransitionAppendsEvidenceAndProvenance(): void
    {
        $earlierFailure = new FailureDetail('The first attempt failed.');
        $earlierTransformation = new OutcomeTransformation(
            'quarantine',
            Outcome::Failed,
            Outcome::Passed,
        );
        $original = new TestResult(
            new TestId('Example\\PolicyTest', 'preservesEvidence'),
            Outcome::Passed,
            0.25,
            1024,
            attempts: 2,
            failures: [$earlierFailure],
            transformations: [$earlierTransformation],
            expectations: 3,
        );
        $originalWire = $original->toWire();
        $policyFailure = new FailureDetail('The result policy rejected the diagnostic.');

        $failed = $original->failedBy('result policy', [$policyFailure]);

        Expect::that($original->toWire())
            ->because('a failure transition MUST NOT change the original result')
            ->toBe($originalWire)
            ->and([
                'outcome' => $failed->outcome,
                'failures' => \array_map(
                    static fn(FailureDetail $failure): array => $failure->toWire(),
                    $failed->failures,
                ),
                'transformations' => \array_map(
                    static fn(OutcomeTransformation $transformation): array => $transformation->toWire(),
                    $failed->transformations,
                ),
                'attempts' => $failed->attempts,
                'expectations' => $failed->expectations,
            ])
            ->because('a failure transition MUST append evidence and provenance')
            ->toBe([
                'outcome' => Outcome::Failed,
                'failures' => [$earlierFailure->toWire(), $policyFailure->toWire()],
                'transformations' => [
                    $earlierTransformation->toWire(),
                    new OutcomeTransformation(
                        'result policy',
                        Outcome::Passed,
                        Outcome::Failed,
                    )->toWire(),
                ],
                'attempts' => 2,
                'expectations' => 3,
            ]);
    }
}
