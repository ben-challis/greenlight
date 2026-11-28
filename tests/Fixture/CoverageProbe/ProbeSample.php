<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\CoverageProbe;

use Stringable;

function probeSampleHelper(int $x): int
{
    if ($x > 1) {
        return $x * 2;
    }

    return match ($x) {
        0 => 10,
        default => 20,
    };
}

final class ProbeSample implements Stringable
{
    /** @var list<string> */
    private array $items = [];

    public function __construct(private readonly int $seed = 3) {}

    public function add(string $item): self
    {
        $this->items[] = $item;
        foreach ($this->items as $i) {
            echo $i;
        }

        return $this;
    }

    public function __toString(): string
    {
        $f = fn(int $v): int => $v + $this->seed;
        return (string) $f(1);
    }
}

$x = new ProbeSample();
