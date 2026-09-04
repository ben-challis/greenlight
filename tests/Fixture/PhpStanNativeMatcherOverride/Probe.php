<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanNativeMatcherOverride;

use Greenlight\Expect\Expect;

function nativeMatcherOverrideProbe(): void
{
    Expect::that(1)->toBeInt();
}
