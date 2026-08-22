<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Coverage\Collection\Driver\PcovRuntime;
use Greenlight\Doubles\Fake;

final class FailingPcovDriverRuntime implements Fake, PcovRuntime
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    public ?\Throwable $startFailure = null;

    public ?\Throwable $collectFailure = null;

    public ?\Throwable $stopFailure = null;

    public ?\Throwable $clearFailure = null;

    #[\Override]
    public function start(): void
    {
        $this->calls[] = 'start';

        if ($this->startFailure instanceof \Throwable) {
            throw $this->startFailure;
        }
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

    #[\Override]
    public function clear(): void
    {
        $this->calls[] = 'clear';

        if ($this->clearFailure instanceof \Throwable) {
            throw $this->clearFailure;
        }
    }
}
