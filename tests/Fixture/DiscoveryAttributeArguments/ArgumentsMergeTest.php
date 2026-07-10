<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryAttributeArguments;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Condition\PhpVersionAtLeast;

#[SkipUnless(EnvironmentVariableEquals::class, 'GREENLIGHT_MERGE_PROBE', 'on')]
final class ArgumentsMergeTest
{
    #[Test]
    public function inheritsClassCondition(): void {}

    #[Test]
    #[SkipUnless(PhpVersionAtLeast::class, '8.0')]
    public function overridesClassCondition(): void {}
}
