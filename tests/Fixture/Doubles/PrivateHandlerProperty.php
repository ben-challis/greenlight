<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

class PrivateHandlerProperty
{
    private mixed $__greenlightHandler = null;

    public function handlerStorage(): mixed
    {
        return $this->__greenlightHandler;
    }
}
