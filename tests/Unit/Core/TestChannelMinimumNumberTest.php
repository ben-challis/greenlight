<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestChannel;
use Greenlight\Expect\Expect;

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
