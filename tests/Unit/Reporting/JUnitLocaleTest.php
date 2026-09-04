<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\RunFinished;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Test\SkipTest;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\SimpleXml;

final readonly class JUnitLocaleTest
{
    #[Test]
    public function durationsUseDecimalPointsWithACommaNumericLocale(): void
    {
        $previous = \setlocale(\LC_NUMERIC, "0");

        try {
            $locale = \setlocale(\LC_NUMERIC, "de_DE.UTF-8", "de_DE.utf8", "de_DE", "fr_FR.UTF-8", "fr_FR");

            if ($locale === false) {
                throw new SkipTest("No decimal-comma locale is available.");
            }

            Expect::that(\localeconv()["decimal_point"])->toBe(",");
            $output = new BufferOutput();
            $reporter = new JUnitReporter($output);
            $reporter->onEvent(new TestFinished(new TestResult(
                new TestId(self::class, __FUNCTION__),
                Outcome::Passed,
                0.125,
                1,
            ), 1.0));
            $reporter->onEvent(new RunFinished("locale-run", new ResultSummary(passed: 1), 0.5, 1.5));
            $reporter->finish();
            $document = \simplexml_load_string($output->buffer());
            Expect::that($document)->toBeInstanceOf(\SimpleXMLElement::class);
            Expect::that((string) $document["time"])->toBe("0.500000");
            Expect::that((string) SimpleXml::xpath($document, "/testsuites/testsuite")[0]["time"])->toBe("0.125000");
            Expect::that((string) SimpleXml::xpath($document, "/testsuites/testsuite/testcase")[0]["time"])->toBe("0.125000");
        } finally {
            if ($previous !== false) {
                \setlocale(\LC_NUMERIC, $previous);
            }
        }
    }
}
