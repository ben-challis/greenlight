<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\WorkerCapacity;

use Fidry\CpuCoreCounter\CpuCoreCounter;
use Fidry\CpuCoreCounter\NumberOfCpuCoreNotFound;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Cli\WorkerCapacity\CpuCores;
use Greenlight\Condition\OperatingSystemFamily;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Cli\WorkerCapacity\FakeCpuCoreCounter;
use Greenlight\Tests\Fixture\Cli\WorkerCapacity\FakeNumberOfCpuCoreNotFound;
use Greenlight\Tests\Support\PhpSubprocess;
use Greenlight\Tests\Support\ProcessResult;
use Greenlight\Tests\Support\SourceOnlyPhp;

final readonly class CpuCoresTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    #[Isolated]
    public function countUsesTheOptionalCpuCounterWhenItIsAvailable(): void
    {
        $this->installFakeOptionalCounter(notFound: false);

        Expect::that(CpuCores::count())
            ->because('the optional CPU counter supplies the worker count')
            ->toBe(7);
        Expect::that(FakeCpuCoreCounter::$calls)
            ->because('the optional CPU counter runs once')
            ->toBe(1);
    }

    #[Test]
    #[Isolated]
    public function countFallsBackWhenTheOptionalCpuCounterCannotFindACount(): void
    {
        $this->installFakeOptionalCounter(notFound: true);

        Expect::that(CpuCores::count())
            ->because('the typed not-found error falls through to the built-in probe')
            ->toBeGreaterThan(0);
        Expect::that(FakeCpuCoreCounter::$calls)
            ->because('the fallback follows one optional attempt')
            ->toBe(1);
    }

    #[Test]
    public function countUsesTheBuiltInProbeWithoutTheOptionalPackage(): void
    {
        $result = $this->runBuiltInProbe();

        Expect::that($result->exitCode)
            ->because('the built-in CPU probe runs without the optional package')
            ->toBe(0);
        Expect::that($result->stdout)
            ->because('the built-in CPU probe returns a positive integer')
            ->toMatch('/^[1-9]\d*$/D');
    }

    #[Test]
    #[SkipUnless(OperatingSystemFamily::class, 'Darwin')]
    #[DataSet('malformedDarwinProbeOutputs')]
    public function malformedDarwinProbeOutputUsesTheConservativeDefault(string $output): void
    {
        $bin = $this->tempDirectory->subdirectory('darwin-cpu-probe');
        $sysctl = $bin . '/sysctl';
        $written = \file_put_contents(
            $sysctl,
            "#!/bin/sh\nprintf '%s\\n' " . \escapeshellarg($output) . "\n",
        );

        if ($written === false || !\chmod($sysctl, 0o700)) {
            Fail::because('The test could not install its CPU probe.');
        }

        $result = $this->runBuiltInProbe(['PATH' => $bin]);

        Expect::that($result->exitCode)
            ->because('the built-in CPU probe MUST complete for malformed system output')
            ->toBe(0);
        Expect::that($result->stdout)
            ->because('malformed system output MUST use the conservative CPU count')
            ->toBe('4');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedDarwinProbeOutputs(): iterable
    {
        yield 'numeric prefix' => ['8cores'];
        yield 'integer overflow' => [\str_repeat('9', 30)];
    }

    private function installFakeOptionalCounter(bool $notFound): void
    {
        if (\class_exists(CpuCoreCounter::class, false) || \class_exists(NumberOfCpuCoreNotFound::class, false)) {
            Fail::because('Expected an isolated worker without loaded optional CPU counter classes.');
        }

        \class_alias(FakeNumberOfCpuCoreNotFound::class, NumberOfCpuCoreNotFound::class);
        \class_alias(FakeCpuCoreCounter::class, CpuCoreCounter::class);
        FakeCpuCoreCounter::$notFound = $notFound;
        FakeCpuCoreCounter::$calls = 0;
    }

    /**
     * @param array<string, string> $environment
     */
    private function runBuiltInProbe(array $environment = []): ProcessResult
    {
        $root = \dirname(__DIR__, 4);

        return PhpSubprocess::run($root, SourceOnlyPhp::command(
            $root . '/src',
            <<<'PHP'
            if (class_exists(\Fidry\CpuCoreCounter\CpuCoreCounter::class)) {
                exit(2);
            }

            echo \Greenlight\Cli\WorkerCapacity\CpuCores::count();
            PHP,
        ), $environment);
    }
}
