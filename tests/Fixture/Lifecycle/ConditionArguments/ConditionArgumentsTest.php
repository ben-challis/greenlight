<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\ConditionArguments;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Condition\PhpVersionAtLeast;

final class ConditionArgumentsTest
{
    #[Test]
    #[SkipUnless(EnvironmentVariableEquals::class, 'GREENLIGHT_STDLIB_NOPE', 'yes')]
    public function skipsWhenTheVariableDiffers(): void
    {
        // Never reached: the environment variable is not set to "yes".
    }

    #[Test]
    #[SkipUnless(PhpVersionAtLeast::class, '8.0')]
    public function runsWhenTheVersionIsSatisfied(): void
    {
        // Runs on every supported PHP version.
    }
}
