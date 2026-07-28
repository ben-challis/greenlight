<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

class PrivateHandlerMethod
{
    public function __construct()
    {
        $this->__greenlightAttachHandler();
    }

    private function __greenlightAttachHandler(): void {}
}
