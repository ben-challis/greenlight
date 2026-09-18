<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Collection;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ExtensionLoaded;
use Greenlight\Tests\Support\PhpSubprocess;

use function Greenlight\expect;

final class XdebugDriverIniFallbackTest
{
    #[Test]
    #[SkipUnless(ExtensionLoaded::class, 'xdebug')]
    public function availabilityUsesTheIniModeWhenXdebugInfoIsDisabled(): void
    {
        $root = \dirname(__DIR__, 4);
        $result = PhpSubprocess::run($root, [
            '-d',
            'xdebug.mode=coverage',
            '-d',
            'disable_functions=xdebug_info',
            '-r',
            <<<'PHP'
                require 'vendor/autoload.php';

                echo \function_exists('xdebug_info') ? "enabled\n" : "disabled\n";
                echo \Greenlight\Coverage\Collection\Driver\XdebugDriver::isAvailable()
                    ? "available\n"
                    : "unavailable\n";
                PHP,
        ]);

        expect($result->exitCode)
            ->because('the isolated Xdebug availability probe MUST exit successfully')
            ->toBe(0);

        expect($result->stdout)
            ->because('Xdebug availability MUST fall back to the configured INI mode')
            ->toBe("disabled\navailable");
    }
}
