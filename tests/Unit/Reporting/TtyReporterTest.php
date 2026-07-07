<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\TtyReporter;

final class TtyReporterTest
{
    #[Test]
    public function cannedStreamWithoutAnsiRendersTheGoldenOutput(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new TtyReporter($output, ansi: false, seed: 424242));

        $expected = <<<'TXT'
            Running 6 tests on 2 workers

            Acme\CalculatorTest .F.
            Acme\NetworkTest ES.

            FAIL Acme\CalculatorTest::subtractsIntegers
              Failed asserting that two values are equal.
              expected: 2
              actual: 3
              at /project/tests/CalculatorTest.php:42

            ERROR Acme\NetworkTest::connects
              RuntimeException: Connection refused.
                Acme\NetworkTest::connect at /project/tests/NetworkTest.php:17
              at /project/tests/NetworkTest.php:17

            Slowest tests:
              0.340s Acme\CalculatorTest::multipliesIntegers[large numbers]
              0.150s Acme\NetworkTest::retriesFlakyEndpoint
              0.020s Acme\CalculatorTest::subtractsIntegers
              0.012s Acme\CalculatorTest::addsIntegers
              0.005s Acme\NetworkTest::connects

            Memory: 263.5 KB total delta, 256.0 KB peak test delta
            Seed: 424242

            Tests: 6, Passed: 3, Failed: 1, Errored: 1, Skipped: 1 (1.234s)
            TXT;

        new Expect()->that($output->buffer())->toBe($expected . "\n");
    }

    #[Test]
    public function cannedStreamWithAnsiColoursOutcomesAndHeadings(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new TtyReporter($output, ansi: true, seed: 424242));

        $expected = <<<TXT
            Running 6 tests on 2 workers

            Acme\CalculatorTest \e[32m.\e[0m\e[31mF\e[0m\e[32m.\e[0m
            Acme\NetworkTest \e[31mE\e[0m\e[33mS\e[0m\e[32m.\e[0m

            \e[31mFAIL Acme\CalculatorTest::subtractsIntegers\e[0m
              Failed asserting that two values are equal.
              expected: 2
              actual: 3
              at /project/tests/CalculatorTest.php:42

            \e[31mERROR Acme\NetworkTest::connects\e[0m
              RuntimeException: Connection refused.
                Acme\NetworkTest::connect at /project/tests/NetworkTest.php:17
              at /project/tests/NetworkTest.php:17

            Slowest tests:
              0.340s Acme\CalculatorTest::multipliesIntegers[large numbers]
              0.150s Acme\NetworkTest::retriesFlakyEndpoint
              0.020s Acme\CalculatorTest::subtractsIntegers
              0.012s Acme\CalculatorTest::addsIntegers
              0.005s Acme\NetworkTest::connects

            Memory: 263.5 KB total delta, 256.0 KB peak test delta
            Seed: 424242

            \e[31mTests: 6, Passed: 3, Failed: 1, Errored: 1, Skipped: 1 (1.234s)\e[0m
            TXT;

        new Expect()->that($output->buffer())->toBe($expected . "\n");
    }

    #[Test]
    public function seedLineIsOmittedWhenTheRunWasNotRandomized(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new TtyReporter($output, ansi: false));

        new Expect()->that($output->buffer())->not()->toContain('Seed:');
    }
}
