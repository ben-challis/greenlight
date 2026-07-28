<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Harness;

use Greenlight\Doubles\Fake;

class FactoryContractTarget implements Fake
{
    private string $value = 'ready';

    public function value(): string
    {
        return $this->value;
    }
}
