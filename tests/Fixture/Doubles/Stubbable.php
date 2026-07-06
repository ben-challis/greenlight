<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface Stubbable
{
    public function name(): string;

    public function count(): int;

    public function ratio(): float;

    public function flag(): bool;

    /**
     * @return list<mixed>
     */
    public function items(): array;

    public function maybeId(): ?int;

    public function touch(): void;

    public function clock(): Clock;

    public function itself(): static;
}
