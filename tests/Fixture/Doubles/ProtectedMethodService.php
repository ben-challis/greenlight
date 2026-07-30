<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

class ProtectedMethodService
{
    final public function message(): string
    {
        return $this->prefix() . 'message';
    }

    protected function prefix(): string
    {
        return 'original ';
    }
}
