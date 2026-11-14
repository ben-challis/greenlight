<?php

declare(strict_types=1);

namespace HyperfBridgeAcceptance\Blocked;

use Greenlight\Attribute\DataSet;
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
    #[DataSet('attempts')]
    public function waitsOnAChannelWithNoProducer(string $attempt): void
    {
        expect(new Channel(1)->pop())->because($attempt)->toBeFalse();
    }

    #[Test]
    public function runsInACoroutine(): void
    {
        expect(Coroutine::inCoroutine())->toBeTrue();
    }

    /** @return iterable<string, array{string}> */
    public static function attempts(): iterable
    {
        yield 'first' => ['first'];
        yield 'second' => ['second'];
    }
}
