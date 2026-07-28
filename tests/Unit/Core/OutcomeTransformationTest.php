<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\OutcomeTransformation;
use Greenlight\Expect\Expect;

final readonly class OutcomeTransformationTest
{
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
