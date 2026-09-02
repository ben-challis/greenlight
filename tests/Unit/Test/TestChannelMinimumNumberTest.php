<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Test\TestChannel;

use function Greenlight\expect;

final readonly class TestChannelMinimumNumberTest
{
    #[Test]
    public function channelOneIsTheMinimumValidChannel(): void
    {
        $channel = new TestChannel(1);

        expect($channel->number)
            ->because('channel one MUST be the first valid test channel')
            ->toBe(1);
        expect($channel->label())
            ->toBe('gl-1');
    }
}
