<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\Subprocess;

final class CpuCoresTest
{
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
}
