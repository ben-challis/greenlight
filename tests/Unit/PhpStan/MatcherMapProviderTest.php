<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\MatcherMapProvider;

final class MatcherMapProviderTest
{
    #[Test]
    public function oneMatcherMapIsSharedAcrossEveryConsumer(): void
    {
        $provider = new MatcherMapProvider([]);
        $first = $provider->get();

        Expect::that($provider->get())
            ->because('PHPStan extensions MUST share one lazily loaded matcher map')
            ->toBe($first);
        Expect::that($first->names())
            ->toBe([]);
    }
}
