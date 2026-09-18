<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\Test;
use Greenlight\Result\Outcome;
use Greenlight\Result\OutcomeTransformation;
use Greenlight\Tests\Support\JsonWire;

use function Greenlight\expect;

final readonly class OutcomeTransformationTest
{
    #[Test]
    public function retainsAZeroSourceAcrossTheWire(): void
    {
        $transformation = new OutcomeTransformation('0', Outcome::Failed, Outcome::Skipped);
        $decoded = OutcomeTransformation::fromWire(JsonWire::roundTrip($transformation->toWire()));

        expect($transformation->transformedBy)
            ->because('an outcome transformation MUST retain each non-empty source')
            ->toBe('0');
        expect($decoded->transformedBy)
            ->because('the transformation source MUST survive the wire')
            ->toBe('0');
        expect($decoded->from)
            ->toBe(Outcome::Failed);
        expect($decoded->to)
            ->toBe(Outcome::Skipped);
    }

    #[Test]
    public function rejectsAnEmptySource(): void
    {
        expect()->calling(
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
