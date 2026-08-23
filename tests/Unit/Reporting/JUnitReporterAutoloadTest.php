<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Sandbox\Autoloaders;
use Greenlight\Test\TestId;

final readonly class JUnitReporterAutoloadTest
{
    public function __construct(private Autoloaders $autoloaders) {}

    #[Test]
    public function autoloaderFailuresDoNotStopReportGeneration(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $class = 'Acme\BrokenJUnitAutoloaderTest';
        $autoloadCalls = 0;
        $autoload = static function (string $requested) use ($class, &$autoloadCalls): void {
            if ($requested === $class) {
                ++$autoloadCalls;

                throw new \RuntimeException('autoload failed');
            }
        };
        $this->autoloaders->register($autoload);

        foreach ([null, 'second data set'] as $dataSetKey) {
            $reporter->onEvent(new TestFinished(new TestResult(
                new TestId($class, 'runs', $dataSetKey),
                Outcome::Passed,
                0.001,
                0,
            ), 1.0));
        }

        $reporter->finish();

        Expect::that($autoloadCalls)
            ->because('a failed source lookup MUST not rerun the autoloader for the same method')
            ->toBe(1);
        Expect::that(\simplexml_load_string($output->buffer()))
            ->because('an autoloader failure MUST preserve valid JUnit XML')
            ->toBeInstanceOf(\SimpleXMLElement::class);
        Expect::that($output->buffer())
            ->because('an autoloader failure MUST omit the optional source file')
            ->not()
            ->toContain(' file=');
    }
}
