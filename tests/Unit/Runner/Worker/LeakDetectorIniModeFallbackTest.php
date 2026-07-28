<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\Subprocess;

final readonly class LeakDetectorIniModeFallbackTest
{
    #[Test]
    public function iniModeIsUsedWhenXdebugInfoIsDisabled(): void
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
                'xdebug.mode=develop',
                '-d',
                'disable_functions=xdebug_info',
                '-r',
                <<<'PHP'
                require $argv[1];

                echo Greenlight\Runner\Worker\LeakDetector::environmentWarning() ?? 'no warning';
                PHP,
                $root . '/vendor/autoload.php',
            ],
        );

        Expect::that($result->exitCode)
            ->because('the runtime fallback process MUST exit successfully')
            ->toBe(0)
            ->and($result->stderr)
            ->toBe('')
            ->and($result->stdout)
            ->because('the ini mode MUST preserve the leak-detection warning when xdebug_info is disabled')
            ->toBe(
                'Warning: Xdebug develop mode keeps caught exceptions in memory. Thus, leak detection reports '
                . 'false positives. Rerun with XDEBUG_MODE=off to get correct results.',
            );
    }
}
