<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakePollingClock;

final readonly class TemporalRetryCompositionTest
{
    #[Test]
    public function repeatedRetryConfigurationAccumulatesExceptionTypes(): void
    {
        $clock = new FakePollingClock();
        $calls = 0;
        $responses = [
            new \RuntimeException('first transient failure'),
            new \LogicException('second transient failure'),
            'ready',
        ];

        ExpectationRuntime::withClock($clock, static function () use (&$calls, &$responses): void {
            Expect::eventually(static function () use (&$calls, &$responses): string {
                ++$calls;
                $response = \array_shift($responses);

                if ($response instanceof \Exception) {
                    throw $response;
                }

                return $response ?? 'ready';
            })
                ->retryOnException(\RuntimeException::class)
                ->retryOnException(\LogicException::class)
                ->pollEvery(0.010)
                ->within(0.100)
                ->toBe('ready');
        });

        Expect::that($calls)
            ->because('repeated retry configuration MUST accumulate exception types')
            ->toBe(3)
            ->and($clock->sleeps)
            ->toBe([0.010, 0.010]);
    }
}
