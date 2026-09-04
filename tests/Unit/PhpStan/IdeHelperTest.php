<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\IdeHelper;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\Tests\Support\FixturePath;

final class IdeHelperTest
{
    #[Test]
    public function rendersOneMethodAnnotationPerMatcherWithReflectedSignatures(): void
    {
        $map = MatcherMap::fromConfigFiles([FixturePath::get('PhpStanExtension/greenlight.php')]);

        $rendered = IdeHelper::render($map);

        Expect::that($rendered)->because('renders one method annotation per matcher with reflected signatures')->toContain('namespace Greenlight\Expect;')
            ->toContain(' * @method self toBeHexadecimal()')
            ->toContain(' * @method self toHaveDigestLength(int $length)')
            ->toContain(' * @method self toBePositive()')
            ->toContain(' * @method Expectation<T> toBeWithin(float $delta, float $of)')
            ->toContain(' * @method Expectation<T> toHaveDigestLength(int $length)')
            ->toContain('final class Expectation {}')
            ->toContain('The IDE does not execute or autoload');
    }

    #[Test]
    public function preservesParenthesesAroundIntersectionsInsideUnions(): void
    {
        $map = MatcherMap::fromConfigFiles([FixturePath::get('PhpStanIdeHelperDnf/greenlight.php')]);

        Expect::that(IdeHelper::render($map))
            ->because('generated matcher annotations preserve disjunctive normal form types')
            ->toContain(' * @method self toCompareWith((\\Countable&\\Iterator)|string $comparison)');
    }
}
