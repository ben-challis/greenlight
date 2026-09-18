<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\PhpStan\MatcherMapProvider;

use function Greenlight\expect;

final class MatcherMapProviderTest
{
    #[Test]
    public function oneMatcherMapIsSharedAcrossEveryConsumer(): void
    {
        $provider = new MatcherMapProvider([]);
        $first = $provider->get();

        expect($provider->get())
            ->because('PHPStan extensions MUST share one lazily loaded matcher map')
            ->toBe($first);
        expect($first->names())
            ->toBe([]);
    }
}
