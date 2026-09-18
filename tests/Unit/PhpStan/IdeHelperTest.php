<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\PhpStan\IdeHelper;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\Tests\Support\FixturePath;

use function Greenlight\expect;

final class IdeHelperTest
{
    #[Test]
    public function rendersOneMethodAnnotationPerMatcherWithReflectedSignatures(): void
    {
        $map = MatcherMap::fromConfigFiles([FixturePath::get('PhpStanExtension/greenlight.php')]);

        $rendered = IdeHelper::render($map);

        expect($rendered)->because('renders one method annotation per matcher with reflected signatures')->toContain('namespace Greenlight\Expect;')
            ->toContain(' * @method self toBeHexadecimal()')
            ->toContain(' * @method self toHaveDigestLength(int $length)')
            ->toContain(' * @method self toBePositive()')
            ->not()->toContain('@method Expectation<T> toBeWithin')
            ->toContain(' * @method Expectation<T> toHaveDigestLength(int $length)')
            ->toContain('class Expectation {}')
            ->toContain('The IDE does not execute or autoload');
    }

    #[Test]
    public function preservesParenthesesAroundIntersectionsInsideUnions(): void
    {
        $map = MatcherMap::fromConfigFiles([FixturePath::get('PhpStanIdeHelperDnf/greenlight.php')]);

        expect(IdeHelper::render($map))
            ->because('generated matcher annotations preserve disjunctive normal form types')
            ->toContain(' * @method self toCompareWith((\\Countable&\\Iterator)|string $comparison)');
    }
}
