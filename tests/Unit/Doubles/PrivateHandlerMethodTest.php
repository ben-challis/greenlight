<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\PrivateHandlerMethod;

final class PrivateHandlerMethodTest
{
    #[Test]
    public function privateParentHandlerMethodsRemainValid(): void
    {
        Expect::that(new Doubles()->stub(PrivateHandlerMethod::class))
            ->because('a private parent method does not conflict with the proxy handler method')
            ->toBeInstanceOf(PrivateHandlerMethod::class);
    }
}
