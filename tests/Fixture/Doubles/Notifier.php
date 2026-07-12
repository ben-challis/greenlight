<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface Notifier
{
    public function notify(string $channel, string $message): void;

    public function flush(): void;

    public function tag(string $first, int ...$rest): void;
}
