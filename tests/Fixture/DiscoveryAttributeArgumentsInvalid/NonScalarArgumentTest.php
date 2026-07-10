<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryAttributeArgumentsInvalid;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;

final class NonScalarArgumentTest
{
    #[Test]
    #[SkipUnless(EnvironmentVariableEquals::class, ['not', 'a', 'scalar'], 'value')]
    public function neverDiscovered(): void {}
}
