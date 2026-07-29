<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

use Greenlight\Doubles\Fake;

class PublicStaticHandlerPropertyCollision implements Fake
{
    public static mixed $__greenlightHandler;
}
