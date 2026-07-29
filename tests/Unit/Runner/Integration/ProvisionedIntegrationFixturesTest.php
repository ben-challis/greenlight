<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Integration;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Integration\ProvisionedIntegrationFixtures;

final readonly class ProvisionedIntegrationFixturesTest
{
    #[Test]
    public function closeRunsEveryCleanupInReverseOrderAndReturnsEveryFailure(): void
    {
        $session = new ProvisionedIntegrationFixtures();
        $trace = [];
        $networkFailure = new \RuntimeException('network cleanup failed');
        $cacheFailure = new \LogicException('cache cleanup failed');

        $session->defer('network', static function () use (&$trace, $networkFailure): void {
            $trace[] = 'network';

            throw $networkFailure;
        });
        $session->defer('database', static function () use (&$trace): void {
            $trace[] = 'database';
        });
        $session->defer('cache', static function () use (&$trace, $cacheFailure): void {
            $trace[] = 'cache';

            throw $cacheFailure;
        });

        Expect::that($session->close())
            ->because('cleanup failures MUST NOT prevent later cleanup callbacks from running')
            ->toBe([
                ['cache', $cacheFailure],
                ['network', $networkFailure],
            ]);
        Expect::that($trace)
            ->because('cleanup callbacks MUST run in reverse acquisition order')
            ->toBe(['cache', 'database', 'network']);
        Expect::that($session->close())
            ->because('closing an integration fixture session more than once MUST be safe')
            ->toBe([]);
    }
}
