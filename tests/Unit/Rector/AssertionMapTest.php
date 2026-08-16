<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Rector;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Rector\AssertionConversion;
use Greenlight\Rector\AssertionMap;

final class AssertionMapTest
{
    /**
     * @param array{
     *     matcher: non-empty-string,
     *     subject: int<0, max>,
     *     matcherArguments: list<int<0, max>>,
     *     arity: int<1, max>,
     *     negated: bool
     * }|null $expected
     */
    #[Test]
    #[DataSet('lookups')]
    public function lookupPreservesConversionMetadata(string $method, ?array $expected): void
    {
        $conversion = AssertionMap::lookup($method);

        if ($expected === null) {
            Expect::that($conversion)->because('unknown assertions have no conversion')->toBeNull();

            return;
        }

        if (!$conversion instanceof AssertionConversion) {
            Fail::because(\sprintf('Expected a conversion for PHPUnit assertion "%s".', $method));
        }

        Expect::that($conversion->matcher)->because('lookup preserves conversion metadata')->toBe($expected['matcher']);
        Expect::that($conversion->subject)->toBe($expected['subject']);
        Expect::that($conversion->matcherArguments)->toBe($expected['matcherArguments']);
        Expect::that($conversion->arity)->toBe($expected['arity']);
        Expect::that($conversion->negated)->toBe($expected['negated']);
    }

    /**
     * @return iterable<string, array{string, array{
     *     matcher: non-empty-string,
     *     subject: int<0, max>,
     *     matcherArguments: list<int<0, max>>,
     *     arity: int<1, max>,
     *     negated: bool
     * }|null}>
     */
    public static function lookups(): iterable
    {
        yield 'case-insensitive negation' => [
            'AsSeRtNoTsAmE',
            [
                'matcher' => 'toBe',
                'subject' => 1,
                'matcherArguments' => [0],
                'arity' => 2,
                'negated' => true,
            ],
        ];

        yield 'delta argument reordering' => [
            'assertEqualsWithDelta',
            [
                'matcher' => 'toBeWithin',
                'subject' => 1,
                'matcherArguments' => [2, 0],
                'arity' => 3,
                'negated' => false,
            ],
        ];

        yield 'unknown assertion' => ['assertSomethingElse', null];
    }
}
