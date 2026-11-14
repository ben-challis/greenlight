<?php

declare(strict_types=1);

namespace HyperfBridgeAcceptance\Blocked;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Hyperf\Coroutine\Coroutine;
use Swoole\Coroutine\Channel;

use function Greenlight\expect;

/**
 * The Cleanup suite runs this class in a separate Greenlight process and checks
 * its report. The blocked tests must fail.
 */
final readonly class HyperfBlockedCoroutineTest
{
    #[Test]
    #[Timeout(seconds: 0.5)]
    public function waitsOnAChannelWithNoProducer(): void
    {
        expect(new Channel(1)->pop())->toBeFalse();
    }

    #[Test]
    #[Timeout(seconds: 0.5)]
    public function waitsAgainOnAChannelWithNoProducer(): void
    {
        expect(new Channel(1)->pop())->toBeFalse();
    }

    #[Test]
    public function runsInACoroutine(): void
    {
        expect(Coroutine::inCoroutine())->toBeTrue();
    }
}
