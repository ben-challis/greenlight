<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Cli;

use Greenlight\Cli\SignalOperations;
use Greenlight\Doubles\Fake;

final class RecordingSignalOperations implements SignalOperations, Fake
{
    public bool $asyncEnabled = false;

    /**
     * @var list<array{signal: int, handler: callable|int}>
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

    #[\Override]
    public function register(int $signal, callable|int $handler): void
    {
        $this->registrations[] = [
            'signal' => $signal,
            'handler' => $handler,
        ];
    }
}
