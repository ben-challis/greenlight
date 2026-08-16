<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\OutcomeTransformation;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final readonly class OutcomeTransformationTest
{
    #[Test]
    public function retainsAZeroSourceAcrossTheWire(): void
    {
        $transformation = new OutcomeTransformation('0', Outcome::Failed, Outcome::Skipped);
        $decoded = OutcomeTransformation::fromWire(JsonWire::roundTrip($transformation->toWire()));

        Expect::that($transformation->transformedBy)
            ->because('an outcome transformation MUST retain each non-empty source')
            ->toBe('0');
        Expect::that($decoded->transformedBy)
            ->because('the transformation source MUST survive the wire')
            ->toBe('0');
        Expect::that($decoded->from)
            ->toBe(Outcome::Failed);
        Expect::that($decoded->to)
            ->toBe(Outcome::Skipped);
    }

    #[Test]
    public function rejectsAnEmptySource(): void
    {
        Expect::that(
            static fn(): OutcomeTransformation => new OutcomeTransformation(
                '',
                Outcome::Failed,
                Outcome::Skipped,
            ),
        )
            ->because('an outcome transformation MUST identify its source')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Outcome transformation source must not be empty.',
            );
    }
}
