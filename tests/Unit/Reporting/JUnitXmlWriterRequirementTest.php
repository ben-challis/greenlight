<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\TestFinished;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;
use Greenlight\Tests\Fixture\Reporting\UnavailableXmlWriterRuntime;

use function Greenlight\expect;

final readonly class JUnitXmlWriterRequirementTest
{
    #[Test]
    public function finishingWithoutXmlWriterFailsExactly(): void
    {
        $reporter = new JUnitReporter(new BufferOutput(), new UnavailableXmlWriterRuntime());

        expect()->calling(static fn() => $reporter->finish())
            ->because('finishing JUnit output MUST require XMLWriter')
            ->toThrow(
                ReportGenerationFailed::class,
                message: 'The XMLWriter extension is required for JUnit output. Enable ext-xmlwriter.',
            );
    }

    #[Test]
    public function renderingATestCaseWithoutXmlWriterFailsExactly(): void
    {
        $reporter = new JUnitReporter(new BufferOutput(), new UnavailableXmlWriterRuntime());
        $result = new TestResult(
            new TestId('Example\PassingTest', 'passes'),
            Outcome::Passed,
            0.0,
            0,
        );

        expect()->calling(static fn() => $reporter->onEvent(new TestFinished($result, 1.0)))
            ->because('rendering a JUnit test case MUST require XMLWriter')
            ->toThrow(
                ReportGenerationFailed::class,
                message: 'The XMLWriter extension is required for JUnit output. Enable ext-xmlwriter.',
            );
    }
}
