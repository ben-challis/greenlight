<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final readonly class LeakDetectionRunTest
{
    #[Test]
    public function leakDetectionNamesTheLeakAndFailsTheRun(): void
    {
        $withFlag = $this->run(['run', '--detect-leaks', '--workers=2']);

        Expect::that($withFlag->exitCode)->because('leak detection names the leak and fails the run')->toBe(1);
        Expect::that($withFlag->output())->toContain('Test instance leaks:')
            ->toContain('  Greenlight\Tests\Fixture\LeakSuite\LeakyTest::passesButLeaksItself');

        $withoutFlag = $this->run(['run', '--workers=2']);

        Expect::that($withoutFlag->exitCode)->because('leak detection names the leak and fails the run')->toBe(0);
    }

    #[Test]
    public function leakDetectionWarnsWhenXdebugDevelopModeIsActive(): void
    {
        if (!\extension_loaded('xdebug')) {
            // Xdebug develop mode causes the warning. The test cannot create
            // this environment property without the extension.
            throw new SkipTest('Xdebug is not loaded');
        }

        $develop = $this->run(['run', '--detect-leaks', '--workers=2'], ['XDEBUG_MODE' => 'develop']);

        Expect::that($develop->output())->because('leak detection warns when Xdebug develop mode is active')->toContain('Xdebug develop mode');

        $off = $this->run(['run', '--detect-leaks', '--workers=2'], ['XDEBUG_MODE' => 'off']);

        Expect::that($off->output())->because('leak detection warns when Xdebug develop mode is active')->not()->toContain('Xdebug develop mode');
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     */
    private function run(array $arguments, array $env = []): ProcessResult
    {
        return GreenlightCli::run(\dirname(__DIR__) . '/Fixture/LeakConfig', $arguments, $env);
    }
}
