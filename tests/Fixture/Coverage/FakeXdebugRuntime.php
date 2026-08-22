<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Coverage\Collection\Driver\XdebugRuntime;
use Greenlight\Doubles\Fake;

final class FakeXdebugRuntime implements Fake, XdebugRuntime
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    public int $flags = 0;

    #[\Override]
    public function start(int $flags): void
    {
        $this->calls[] = 'start';
        $this->flags = $flags;
    }

    /**
     * @return array<string, array<int, int>>
     */
    #[\Override]
    public function collect(): array
    {
        $this->calls[] = 'collect';

        return [
            '/src/Example.php' => [
                10 => 1,
                11 => -1,
            ],
        ];
    }

    #[\Override]
    public function stop(): void
    {
        $this->calls[] = 'stop';
    }
}
