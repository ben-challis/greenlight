<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Injection;

final class InjectedProbe
{
    public function ping(): string
    {
        return 'pong';
    }
}
