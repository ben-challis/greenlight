<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\OutcomeTransformation;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

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
            ->toBe($originalWire);
        Expect::that($failed->outcome)
            ->because('a failure transition MUST use the failed outcome')
            ->toBe(Outcome::Failed);
        Expect::that(\array_map(
            static fn(FailureDetail $failure): array => $failure->toWire(),
            $failed->failures,
        ))
            ->because('a failure transition MUST append failure evidence')
            ->toBe([$earlierFailure->toWire(), $policyFailure->toWire()]);
        Expect::that(\array_map(
            static fn(OutcomeTransformation $transformation): array => $transformation->toWire(),
            $failed->transformations,
        ))
            ->because('a failure transition MUST append provenance')
            ->toBe([
                $earlierTransformation->toWire(),
                new OutcomeTransformation(
                    'result policy',
                    Outcome::Passed,
                    Outcome::Failed,
                )->toWire(),
            ]);
        Expect::that($failed->attempts)
            ->because('a failure transition MUST preserve the attempt count')
            ->toBe(2);
        Expect::that($failed->expectations)
            ->because('a failure transition MUST preserve the expectation count')
            ->toBe(3);
    }
}
