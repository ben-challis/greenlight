<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\ProcessResult;
use Greenlight\Tests\Support\Subprocess;

final class CpuCoresTest
{
    #[Test]
    public function countUsesTheOptionalCpuCounterWhenItIsAvailable(): void
    {
        $result = $this->runWithFakeOptionalCounter(notFound: false);

        Expect::that($result->exitCode)
            ->because('the optional CPU counter runs successfully')
            ->toBe(0)
            ->and($result->stdout)
            ->because('the optional CPU counter supplies the exact worker count once')
            ->toBe('7:1');
    }

    #[Test]
    public function countFallsBackWhenTheOptionalCpuCounterCannotFindACount(): void
    {
        $result = $this->runWithFakeOptionalCounter(notFound: true);

        Expect::that($result->exitCode)
            ->because('the typed not-found error falls through to the built-in probe')
            ->toBe(0)
            ->and($result->stdout)
            ->because('the built-in probe returns a positive count after one optional attempt')
            ->toMatch('/^[1-9]\d*:1$/D');
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

    private function runWithFakeOptionalCounter(bool $notFound): ProcessResult
    {
        $root = \dirname(__DIR__, 3);

        return Subprocess::run($root, [
            \PHP_BINARY,
            '-r',
            <<<'PHP'
            require $argv[1];

            class_alias(
                \Greenlight\Tests\Fixture\Runner\FakeNumberOfCpuCoreNotFound::class,
                \Fidry\CpuCoreCounter\NumberOfCpuCoreNotFound::class,
            );
            class_alias(
                \Greenlight\Tests\Fixture\Runner\FakeCpuCoreCounter::class,
                \Fidry\CpuCoreCounter\CpuCoreCounter::class,
            );

            \Greenlight\Tests\Fixture\Runner\FakeCpuCoreCounter::$notFound = $argv[2] === 'not-found';

            $count = \Greenlight\Runner\CpuCores::count();

            echo $count . ':' . \Greenlight\Tests\Fixture\Runner\FakeCpuCoreCounter::$calls;
            PHP,
            $root . '/vendor/autoload.php',
            $notFound ? 'not-found' : 'available',
        ]);
    }
}
