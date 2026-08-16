<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\MatcherMap;

final class MatcherMapPluginFilteringTest
{
    private const string CONFIG = __DIR__ . '/../../Fixture/PhpStanExtensionMixed/greenlight.php';

    #[Test]
    public function ignoresPluginsThatDoNotProvideExpectationMatchers(): void
    {
        $map = MatcherMap::fromConfigFiles([self::CONFIG]);

        Expect::that($map->names())
            ->because('matcher discovery MUST ignore plugins that do not provide expectation matchers')
            ->toBe([
                'toBeHexadecimal',
                'toHaveDigestLength',
                'toBePositive',
            ]);
        Expect::that($map->has('toHaveDigestLength'))
            ->because('matcher discovery MUST retain expectation extensions from a mixed plugin configuration')
            ->toBeTrue();
    }
}
