<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Cli\Signal;

use Greenlight\Cli\Signal\SignalOperations;
use Greenlight\Doubles\Fake;

final class RecordingSignalOperations implements SignalOperations, Fake
{
    public bool $asyncEnabled = false;

    /**
     * @var list<array{signal: int, handler: (callable(int): void)|int}>
     */
    public array $registrations = [];

    public function __construct(private readonly bool $available) {}

    #[\Override]
    public function available(): bool
    {
        return $this->available;
    }

    #[\Override]
    public function enableAsync(): void
    {
        $this->asyncEnabled = true;
    }

    /** @param (callable(int): void)|int $handler */
    #[\Override]
    public function register(int $signal, callable|int $handler): void
    {
        $this->registrations[] = [
            'signal' => $signal,
            'handler' => $handler,
        ];
    }
}
