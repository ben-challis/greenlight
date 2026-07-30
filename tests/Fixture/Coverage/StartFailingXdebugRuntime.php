<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Coverage\Driver\XdebugRuntime;
use Greenlight\Doubles\Fake;

final class StartFailingXdebugRuntime implements Fake, XdebugRuntime
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    #[\Override]
    public function start(int $flags): void
    {
        $this->calls[] = 'start';

        throw new \RuntimeException('Xdebug start failed.');
    }

    #[\Override]
    public function collect(): array
    {
        $this->calls[] = 'collect';

        return [];
    }

    #[\Override]
    public function stop(): void
    {
        $this->calls[] = 'stop';
    }
}
