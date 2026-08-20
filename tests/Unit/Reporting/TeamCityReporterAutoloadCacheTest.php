<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestStarted;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\AutoloaderSandbox;
use Greenlight\Reporting\TeamCityReporter;

final readonly class TeamCityReporterAutoloadCacheTest
{
    public function __construct(private AutoloaderSandbox $autoloaders) {}

    #[Test]
    public function aFailedClassLookupIsCachedAcrossEvents(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);
        $class = 'Acme\BrokenCachedAutoloaderTest';
        $autoloadCalls = 0;
        $autoload = static function (string $requested) use ($class, &$autoloadCalls): void {
            if ($requested === $class) {
                ++$autoloadCalls;

                throw new \RuntimeException('autoload failed');
            }
        };
        $this->autoloaders->register($autoload);

        $reporter->onEvent(new TestClassStarted($class, 1.0, 'w-1'));
        $reporter->onEvent(new TestStarted(new TestId($class, 'runs'), 1.1));

        Expect::that($autoloadCalls)
            ->because('a failed optional location lookup MUST not rerun the autoloader for each event')
            ->toBe(1);
        Expect::that($output->buffer())
            ->because('cached lookup failure MUST not stop later report events')
            ->toBe(
                "##teamcity[testSuiteStarted name='{$class}' flowId='{$class}']\n"
                . "##teamcity[testStarted name='{$class}::runs' flowId='{$class}']\n",
            );
    }
}
