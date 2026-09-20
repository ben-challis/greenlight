<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

final class FactoryIdentityChildPlugin extends FactoryIdentityPlugin
{
    /** @return \Closure(): parent */
    public static function parentFactory(): \Closure
    {
        return static fn(): parent => new parent();
    }
}
