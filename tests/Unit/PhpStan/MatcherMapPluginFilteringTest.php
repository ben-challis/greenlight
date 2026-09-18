<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\PhpStan\MatcherMap;

use function Greenlight\expect;

final class MatcherMapPluginFilteringTest
{
    private const string CONFIG = __DIR__ . '/../../Fixture/PhpStanExtensionMixed/greenlight.php';

    #[Test]
    public function ignoresPluginsThatDoNotProvideExpectationMatchers(): void
    {
        $map = MatcherMap::fromConfigFiles([self::CONFIG]);

        expect($map->names())
            ->because('matcher discovery MUST ignore plugins that do not provide expectation matchers')
            ->toBe([
                'toBeHexadecimal',
                'toHaveDigestLength',
                'toBePositive',
            ]);
        expect($map->has('toHaveDigestLength'))
            ->because('matcher discovery MUST retain expectation extensions from a mixed plugin configuration')
            ->toBeTrue();
    }
}
