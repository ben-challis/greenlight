<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Tests\Fixture\Reporting\UnavailableXmlWriterRuntime;

final readonly class JUnitXmlWriterRequirementTest
{
    #[Test]
    public function finishingWithoutXmlWriterFailsExactly(): void
    {
        $reporter = new JUnitReporter(new BufferOutput(), new UnavailableXmlWriterRuntime());

        Expect::that(static fn() => $reporter->finish())
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

        Expect::that(static fn() => $reporter->onEvent(new TestFinished($result, 1.0)))
            ->because('rendering a JUnit test case MUST require XMLWriter')
            ->toThrow(
                ReportGenerationFailed::class,
                message: 'The XMLWriter extension is required for JUnit output. Enable ext-xmlwriter.',
            );
    }
}
