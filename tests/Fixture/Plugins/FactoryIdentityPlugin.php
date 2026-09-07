<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

use Greenlight\Doubles\Fake;
use Greenlight\Plugin\Plugin;

class FactoryIdentityPlugin implements Fake, Plugin
{
    /** @return \Closure(): self */
    public static function scopedFactory(): \Closure
    {
        return static fn(): self => new self();
    }
}
