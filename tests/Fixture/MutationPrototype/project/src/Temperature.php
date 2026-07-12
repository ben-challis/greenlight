<?php

declare(strict_types=1);

namespace MutationPrototype;

final class Temperature
{
    public function isFreezing(float $celsius): bool
    {
        return $celsius <= 0.0;
    }

    public function describe(float $celsius): string
    {
        return $this->isFreezing($celsius) ? 'freezing' : 'mild';
    }
}
