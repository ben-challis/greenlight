<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Tests\Fixture\Doubles\PrivateHandlerMethod;

use function Greenlight\expect;

final readonly class PrivateHandlerMethodTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function privateParentHandlerMethodsRemainValid(): void
    {
        expect($this->doubles->stub(PrivateHandlerMethod::class))
            ->because('a private parent method does not conflict with the proxy handler method')
            ->toBeInstanceOf(PrivateHandlerMethod::class);
    }
}
