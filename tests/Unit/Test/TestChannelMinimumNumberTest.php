<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\TestChannel;

final readonly class TestChannelMinimumNumberTest
{
    #[Test]
    public function channelOneRepresentsAnInProcessRun(): void
    {
        $channel = new TestChannel(1);

        Expect::that($channel->number)
            ->because('in-process runs MUST use the first valid test channel')
            ->toBe(1);
        Expect::that($channel->label())
            ->toBe('gl-1');
    }
}
