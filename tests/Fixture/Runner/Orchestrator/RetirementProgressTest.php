<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;

final readonly class RetirementProgressTest
{
    #[Test]
    public function recordsProgress(): void
    {
        $marker = \getenv('GREENLIGHT_RETIREMENT_PROGRESS_MARKER');

        Expect::that(\is_string($marker) && $marker !== '')
            ->because('the retirement progress fixture MUST have a marker path')
            ->toBeTrue();

        if (!\is_string($marker) || $marker === '') {
            Fail::because('The retirement progress fixture requires a marker path.');
        }

        \file_put_contents($marker, 'progress');
    }
}
