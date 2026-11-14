<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Reporting\ClassAssignments;

use function Greenlight\expect;

final class ClassAssignmentsTest
{
    #[Test]
    public function onlyTheFirstStartAndTheLastFinishOfASplitClassCount(): void
    {
        $assignments = new ClassAssignments();

        expect([
            $assignments->started('App\AlphaTest'),
            $assignments->started('App\AlphaTest'),
            $assignments->finished('App\AlphaTest'),
            $assignments->finished('App\AlphaTest'),
            $assignments->finished('App\BetaTest'),
            $assignments->started('App\AlphaTest'),
        ])->toBe([true, false, false, true, true, true]);
    }
}
