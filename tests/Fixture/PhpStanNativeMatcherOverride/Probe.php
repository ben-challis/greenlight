<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanNativeMatcherOverride;

use Greenlight\Expect\Expect;

function nativeMatcherOverrideProbe(): void
{
    Expect::value(1)->toBeInt();
}
