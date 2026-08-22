<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\SkipTest;
use Greenlight\Tests\Support\Subprocess;

final readonly class LeakDetectorWhitespaceIniModeTest
{
    #[Test]
    public function iniModeFallbackTrimsCommaSeparatedModes(): void
    {
        if (!\extension_loaded('xdebug')) {
            throw new SkipTest('Xdebug is not loaded');
        }

        $root = \dirname(__DIR__, 4);
        $result = Subprocess::run(
            $root,
            [
                \PHP_BINARY,
                '-d',
                'xdebug.mode=coverage, develop',
                '-d',
                'disable_functions=xdebug_info',
                '-r',
                <<<'PHP'
                require $argv[1];

                echo Greenlight\Execution\Worker\LeakDetector::environmentWarning() ?? 'no warning';
                PHP,
                $root . '/vendor/autoload.php',
            ],
        );

        Expect::that($result->exitCode)
            ->because('the fallback process MUST accept comma-separated Xdebug modes')
            ->toBe(0);
        Expect::that($result->stderr)
            ->toBe('');
        Expect::that($result->stdout)
            ->because('the fallback MUST detect develop mode after separator whitespace')
            ->toBe(
                'Warning: Xdebug develop mode keeps caught exceptions in memory. Thus, leak detection reports '
                . 'false positives. Rerun with XDEBUG_MODE=off to get correct results.',
            );
    }
}
