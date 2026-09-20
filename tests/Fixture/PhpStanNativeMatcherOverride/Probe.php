<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanNativeMatcherOverride;



use function Greenlight\expect;

function nativeMatcherOverrideProbe(): void
{
    expect(1)->toBeInt();
}
