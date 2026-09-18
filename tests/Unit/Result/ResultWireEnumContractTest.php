<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\Test;
use Greenlight\Result\DiagnosticSeverity;
use Greenlight\Result\Outcome;

use function Greenlight\expect;

final class ResultWireEnumContractTest
{
    #[Test]
    public function resultEnumsKeepTheirPublishedWireValues(): void
    {
        expect(\array_column(Outcome::cases(), 'value', 'name'))
            ->because('result outcomes MUST keep their published wire values')
            ->toBe([
                'Passed' => 'passed',
                'Failed' => 'failed',
                'Errored' => 'errored',
                'Skipped' => 'skipped',
            ]);
        expect(\array_column(DiagnosticSeverity::cases(), 'value', 'name'))
            ->because('diagnostic severities MUST keep their published wire values')
            ->toBe([
                'Notice' => 'notice',
                'Warning' => 'warning',
                'Deprecation' => 'deprecation',
            ]);
    }
}
