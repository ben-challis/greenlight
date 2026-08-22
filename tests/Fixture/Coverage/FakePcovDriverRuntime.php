<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Coverage\Collection\Driver\PcovRuntime;
use Greenlight\Doubles\Fake;

final class FakePcovDriverRuntime implements Fake, PcovRuntime
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    #[\Override]
    public function start(): void
    {
        $this->calls[] = 'start';
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

    #[\Override]
    public function clear(): void
    {
        $this->calls[] = 'clear';
    }
}
