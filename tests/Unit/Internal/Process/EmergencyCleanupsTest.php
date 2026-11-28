<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Process;

use Greenlight\Attribute\Test;
use Greenlight\Internal\Process\EmergencyCleanups;

use function Greenlight\expect;

final class EmergencyCleanupsTest
{
    #[Test]
    public function runCallsRegisteredCleanupsOnceNewestFirst(): void
    {
        $cleanups = new EmergencyCleanups();
        $log = [];
        $cleanups->register(static function () use (&$log): void {
            $log[] = 'first';
        });
        $removed = $cleanups->register(static function () use (&$log): void {
            $log[] = 'removed';
        });
        $cleanups->register(static function () use (&$log): void {
            $log[] = 'last';
        });
        $cleanups->unregister($removed);

        $cleanups->run();
        $cleanups->run();

        expect($log)
            ->because('cleanups run once, newest first, and an unregistered cleanup does not run')
            ->toBe(['last', 'first']);
    }

    #[Test]
    public function aFailedCleanupDoesNotStopTheOthers(): void
    {
        $cleanups = new EmergencyCleanups();
        $log = [];
        $cleanups->register(static function () use (&$log): void {
            $log[] = 'first';
        });
        $cleanups->register(static function (): void {
            throw new \RuntimeException('cleanup failed');
        });

        $cleanups->run();

        expect($log)
            ->because('the process exits next, so each remaining cleanup must still run')
            ->toBe(['first']);
    }
}
