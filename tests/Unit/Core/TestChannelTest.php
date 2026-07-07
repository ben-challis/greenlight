<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestChannel;
use Greenlight\Expect\Expect;

final readonly class TestChannelTest
{
    public function __construct(
        private Expect $expect,
    ) {}

    #[Test]
    public function exposesTheSlotNumberAndAPrefixedLabel(): void
    {
        $channel = new TestChannel(3);

        $this->expect->that($channel->number)->toBe(3)
            ->and($channel->label())->toBe('gl-3');
    }
}
