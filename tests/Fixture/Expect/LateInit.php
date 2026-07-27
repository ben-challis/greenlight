<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

final class LateInit
{
    public string $value;

    public function init(): void
    {
        $this->value = 'set';
    }
}
