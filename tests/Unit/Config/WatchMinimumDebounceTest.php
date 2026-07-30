<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\WatchBuilder;
use Greenlight\Expect\Expect;

final class WatchMinimumDebounceTest
{
    #[Test]
    public function oneMillisecondIsAValidDebounce(): void
    {
        $configuration = new WatchBuilder()
            ->debounceMilliseconds(1)
            ->toConfiguration();

        Expect::that($configuration->debounceMilliseconds)
            ->because('watch mode MUST accept its documented minimum debounce')
            ->toBe(1);
    }
}
