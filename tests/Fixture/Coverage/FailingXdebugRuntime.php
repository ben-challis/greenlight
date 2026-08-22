<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Coverage\Collection\Driver\XdebugRuntime;
use Greenlight\Doubles\Fake;

final class FailingXdebugRuntime implements Fake, XdebugRuntime
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    public ?\Throwable $collectFailure = null;

    public ?\Throwable $stopFailure = null;

    #[\Override]
    public function start(int $flags): void
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

        if ($this->collectFailure instanceof \Throwable) {
            throw $this->collectFailure;
        }

        return ['/src/Example.php' => [10 => 1]];
    }

    #[\Override]
    public function stop(): void
    {
        $this->calls[] = 'stop';

        if ($this->stopFailure instanceof \Throwable) {
            throw $this->stopFailure;
        }
    }
}
