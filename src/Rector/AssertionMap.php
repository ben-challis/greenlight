<?php

declare(strict_types=1);

namespace Greenlight\Rector;

/**
 * Maps PHPUnit assertion method names onto Greenlight expectation chains.
 * Lookups are case-insensitive because PHP method calls are. Assertions
 * without a faithful Greenlight equivalent are deliberately absent.
 *
 * @internal
 */
final class AssertionMap
{
    /**
     * Entries are [matcher, subject index, matcher argument indices, arity, negated].
     *
     * @var array<string, array{non-empty-string, int<0, max>, list<int<0, max>>, int<1, max>, bool}>
     */
    private const array MAP = [
        'assertsame' => ['toBe', 1, [0], 2, false],
        'assertnotsame' => ['toBe', 1, [0], 2, true],
        'assertequals' => ['toEqual', 1, [0], 2, false],
        'assertnotequals' => ['toEqual', 1, [0], 2, true],
        'assertequalscanonicalizing' => ['toEqualCanonicalizing', 1, [0], 2, false],
        'assertnotequalscanonicalizing' => ['toEqualCanonicalizing', 1, [0], 2, true],
        'assertequalswithdelta' => ['toBeWithin', 1, [2, 0], 3, false],
        'asserttrue' => ['toBeTrue', 0, [], 1, false],
        'assertnottrue' => ['toBeTrue', 0, [], 1, true],
        'assertfalse' => ['toBeFalse', 0, [], 1, false],
        'assertnotfalse' => ['toBeFalse', 0, [], 1, true],
        'assertnull' => ['toBeNull', 0, [], 1, false],
        'assertnotnull' => ['toBeNull', 0, [], 1, true],
        'assertinstanceof' => ['toBeInstanceOf', 1, [0], 2, false],
        'assertnotinstanceof' => ['toBeInstanceOf', 1, [0], 2, true],
        'assertcount' => ['toHaveCount', 1, [0], 2, false],
        'assertnotcount' => ['toHaveCount', 1, [0], 2, true],
        'assertgreaterthan' => ['toBeGreaterThan', 1, [0], 2, false],
        'assertgreaterthanorequal' => ['toBeGreaterThanOrEqual', 1, [0], 2, false],
        'assertlessthan' => ['toBeLessThan', 1, [0], 2, false],
        'assertlessthanorequal' => ['toBeLessThanOrEqual', 1, [0], 2, false],
        'assertisarray' => ['toBeArray', 0, [], 1, false],
        'assertisnotarray' => ['toBeArray', 0, [], 1, true],
        'assertisstring' => ['toBeString', 0, [], 1, false],
        'assertisnotstring' => ['toBeString', 0, [], 1, true],
        'assertisint' => ['toBeInt', 0, [], 1, false],
        'assertisnotint' => ['toBeInt', 0, [], 1, true],
        'assertisfloat' => ['toBeFloat', 0, [], 1, false],
        'assertisnotfloat' => ['toBeFloat', 0, [], 1, true],
        'assertisbool' => ['toBeBool', 0, [], 1, false],
        'assertisnotbool' => ['toBeBool', 0, [], 1, true],
        'assertiscallable' => ['toBeCallable', 0, [], 1, false],
        'assertisnotcallable' => ['toBeCallable', 0, [], 1, true],
        'assertisiterable' => ['toBeIterable', 0, [], 1, false],
        'assertisnotiterable' => ['toBeIterable', 0, [], 1, true],
        'assertcontains' => ['toContain', 1, [0], 2, false],
        'assertnotcontains' => ['toContain', 1, [0], 2, true],
        'assertstringcontainsstring' => ['toContain', 1, [0], 2, false],
        'assertstringnotcontainsstring' => ['toContain', 1, [0], 2, true],
        'assertarrayhaskey' => ['toHaveKey', 1, [0], 2, false],
        'assertarraynothaskey' => ['toHaveKey', 1, [0], 2, true],
        'assertmatchesregularexpression' => ['toMatch', 1, [0], 2, false],
        'assertdoesnotmatchregularexpression' => ['toMatch', 1, [0], 2, true],
        'assertstringstartswith' => ['toStartWith', 1, [0], 2, false],
        'assertstringstartsnotwith' => ['toStartWith', 1, [0], 2, true],
        'assertstringendswith' => ['toEndWith', 1, [0], 2, false],
        'assertstringendsnotwith' => ['toEndWith', 1, [0], 2, true],
        'assertjson' => ['toBeJson', 0, [], 1, false],
        'assertjsonstringequalsjsonstring' => ['toMatchJson', 1, [0], 2, false],
    ];

    /**
     * @var array<string, AssertionConversion>|null
     */
    private static ?array $entries = null;

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function lookup(string $method): ?AssertionConversion
    {
        return self::entries()[\strtolower($method)] ?? null;
    }

    /**
     * @return array<string, AssertionConversion>
     */
    private static function entries(): array
    {
        if (self::$entries === null) {
            $entries = [];

            foreach (self::MAP as $name => $entry) {
                $entries[$name] = new AssertionConversion($entry[0], $entry[1], $entry[2], $entry[3], $entry[4]);
            }

            self::$entries = $entries;
        }

        return self::$entries;
    }
}
