<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Coverage\Collection\Driver\XdebugRuntime;
use Greenlight\Doubles\Fake;

final class FakeXdebugRuntime implements Fake, XdebugRuntime
{
    public bool $branchCoverage = true;
    /**
     * @var list<string>
     */
    public array $calls = [];

    public int $flags = 0;

    /** @var array<mixed> */
    public array $coverage = [
        '/src/Example.php' => [
            10 => 1,
            11 => -1,
        ],
    ];

    #[\Override]
    public function supportsBranchCoverage(): bool
    {
        return $this->branchCoverage;
    }

    #[\Override]
    public function start(int $flags): void
    {
        $this->calls[] = 'start';
        $this->flags = $flags;
    }

    /**
     * @return array<mixed>
     */
    #[\Override]
    public function collect(): array
    {
        $this->calls[] = 'collect';

        return $this->coverage;
    }

    #[\Override]
    public function stop(): void
    {
        $this->calls[] = 'stop';
    }
}
