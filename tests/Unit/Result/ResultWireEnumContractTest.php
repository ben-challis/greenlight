<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\DiagnosticSeverity;
use Greenlight\Result\Outcome;

final class ResultWireEnumContractTest
{
    #[Test]
    public function resultEnumsKeepTheirPublishedWireValues(): void
    {
        Expect::that(\array_column(Outcome::cases(), 'value', 'name'))
            ->because('result outcomes MUST keep their published wire values')
            ->toBe([
                'Passed' => 'passed',
                'Failed' => 'failed',
                'Errored' => 'errored',
                'Skipped' => 'skipped',
            ]);
        Expect::that(\array_column(DiagnosticSeverity::cases(), 'value', 'name'))
            ->because('diagnostic severities MUST keep their published wire values')
            ->toBe([
                'Notice' => 'notice',
                'Warning' => 'warning',
                'Deprecation' => 'deprecation',
            ]);
    }
}
