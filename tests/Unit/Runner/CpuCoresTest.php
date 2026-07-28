<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Fidry\CpuCoreCounter\CpuCoreCounter;
use Fidry\CpuCoreCounter\NumberOfCpuCoreNotFound;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\CpuCores;
use Greenlight\Tests\Fixture\Runner\FakeCpuCoreCounter;
use Greenlight\Tests\Fixture\Runner\FakeNumberOfCpuCoreNotFound;
use Greenlight\Tests\Support\Subprocess;

final class CpuCoresTest
{
    #[Test]
    #[Isolated]
    public function countUsesTheOptionalCpuCounterWhenItIsAvailable(): void
    {
        $this->installFakeOptionalCounter(notFound: false);

        Expect::that(CpuCores::count())
            ->because('the optional CPU counter supplies the worker count')
            ->toBe(7)
            ->and(FakeCpuCoreCounter::$calls)
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
            ->toBeGreaterThan(0)
            ->and(FakeCpuCoreCounter::$calls)
            ->because('the fallback follows one optional attempt')
            ->toBe(1);
    }

    #[Test]
    public function countUsesTheBuiltInProbeWithoutTheOptionalPackage(): void
    {
        $root = \dirname(__DIR__, 3);
        $result = Subprocess::run($root, [
            \PHP_BINARY,
            '-n',
            '-r',
            <<<'PHP'
            $source = $argv[1];

            spl_autoload_register(static function (string $class) use ($source): void {
                $prefix = 'Greenlight\\';

                if (!str_starts_with($class, $prefix)) {
                    return;
                }

                $path = $source . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

                if (is_file($path)) {
                    require $path;
                }
            });

            if (class_exists(\Fidry\CpuCoreCounter\CpuCoreCounter::class)) {
                exit(2);
            }

            echo \Greenlight\Runner\CpuCores::count();
            PHP,
            $root . '/src',
        ]);

        Expect::that($result->exitCode)
            ->because('the built-in CPU probe runs without the optional package')
            ->toBe(0)
            ->and($result->stdout)
            ->because('the built-in CPU probe returns a positive integer')
            ->toMatch('/^[1-9]\d*$/D');
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
}
