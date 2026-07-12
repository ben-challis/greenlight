<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface Wide
{
    public function unionType(int|string $value): int|string;

    public function intersectionType(Marker&\Countable $value): void;

    public function unionWithIntersection((Marker&\Countable)|string $value): void;

    /**
     * @param list<mixed> $items
     */
    public function byReference(array &$items): void;

    /**
     * @return list<mixed>
     */
    public function variadic(string $first, int ...$rest): array;

    public function returnsStatic(): static;

    public function returnsNever(): never;

    public function returnsVoid(): void;

    public function nullable(?string $name): ?string;

    public function withDefaults(int $limit = 10, string $mode = 'fast', Suit $suit = Suit::Hearts, ?Marker $marker = null): string;
}
