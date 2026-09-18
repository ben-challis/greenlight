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
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function lookup(string $method): ?AssertionConversion
    {
        /** @var array<string, AssertionConversion> $entries */
        static $entries = [
            'assertsame' => new AssertionConversion('toBe', 1, [0], 2, false),
            'assertnotsame' => new AssertionConversion('toBe', 1, [0], 2, true),
            'assertequals' => new AssertionConversion('toEqual', 1, [0], 2, false),
            'assertnotequals' => new AssertionConversion('toEqual', 1, [0], 2, true),
            'assertequalscanonicalizing' => new AssertionConversion('toEqualCanonicalizing', 1, [0], 2, false),
            'assertnotequalscanonicalizing' => new AssertionConversion('toEqualCanonicalizing', 1, [0], 2, true),
            'assertequalswithdelta' => new AssertionConversion('toBeWithin', 1, [2, 0], 3, false),
            'asserttrue' => new AssertionConversion('toBeTrue', 0, [], 1, false),
            'assertnottrue' => new AssertionConversion('toBeTrue', 0, [], 1, true),
            'assertfalse' => new AssertionConversion('toBeFalse', 0, [], 1, false),
            'assertnotfalse' => new AssertionConversion('toBeFalse', 0, [], 1, true),
            'assertnull' => new AssertionConversion('toBeNull', 0, [], 1, false),
            'assertnotnull' => new AssertionConversion('toBeNull', 0, [], 1, true),
            'assertinstanceof' => new AssertionConversion('toBeInstanceOf', 1, [0], 2, false),
            'assertnotinstanceof' => new AssertionConversion('toBeInstanceOf', 1, [0], 2, true),
            'assertcount' => new AssertionConversion('toHaveCount', 1, [0], 2, false),
            'assertnotcount' => new AssertionConversion('toHaveCount', 1, [0], 2, true),
            'assertgreaterthan' => new AssertionConversion('toBeGreaterThan', 1, [0], 2, false),
            'assertgreaterthanorequal' => new AssertionConversion('toBeGreaterThanOrEqual', 1, [0], 2, false),
            'assertlessthan' => new AssertionConversion('toBeLessThan', 1, [0], 2, false),
            'assertlessthanorequal' => new AssertionConversion('toBeLessThanOrEqual', 1, [0], 2, false),
            'assertisarray' => new AssertionConversion('toBeArray', 0, [], 1, false),
            'assertisnotarray' => new AssertionConversion('toBeArray', 0, [], 1, true),
            'assertisstring' => new AssertionConversion('toBeString', 0, [], 1, false),
            'assertisnotstring' => new AssertionConversion('toBeString', 0, [], 1, true),
            'assertisint' => new AssertionConversion('toBeInt', 0, [], 1, false),
            'assertisnotint' => new AssertionConversion('toBeInt', 0, [], 1, true),
            'assertisfloat' => new AssertionConversion('toBeFloat', 0, [], 1, false),
            'assertisnotfloat' => new AssertionConversion('toBeFloat', 0, [], 1, true),
            'assertisbool' => new AssertionConversion('toBeBool', 0, [], 1, false),
            'assertisnotbool' => new AssertionConversion('toBeBool', 0, [], 1, true),
            'assertiscallable' => new AssertionConversion('toBeCallable', 0, [], 1, false),
            'assertisnotcallable' => new AssertionConversion('toBeCallable', 0, [], 1, true),
            'assertisiterable' => new AssertionConversion('toBeIterable', 0, [], 1, false),
            'assertisnotiterable' => new AssertionConversion('toBeIterable', 0, [], 1, true),
            'assertcontains' => new AssertionConversion('toContain', 1, [0], 2, false),
            'assertnotcontains' => new AssertionConversion('toContain', 1, [0], 2, true),
            'assertstringcontainsstring' => new AssertionConversion('toContain', 1, [0], 2, false),
            'assertstringnotcontainsstring' => new AssertionConversion('toContain', 1, [0], 2, true),
            'assertarrayhaskey' => new AssertionConversion('toHaveKey', 1, [0], 2, false),
            'assertarraynothaskey' => new AssertionConversion('toHaveKey', 1, [0], 2, true),
            'assertmatchesregularexpression' => new AssertionConversion('toMatch', 1, [0], 2, false),
            'assertdoesnotmatchregularexpression' => new AssertionConversion('toMatch', 1, [0], 2, true),
            'assertstringstartswith' => new AssertionConversion('toStartWith', 1, [0], 2, false),
            'assertstringstartsnotwith' => new AssertionConversion('toStartWith', 1, [0], 2, true),
            'assertstringendswith' => new AssertionConversion('toEndWith', 1, [0], 2, false),
            'assertstringendsnotwith' => new AssertionConversion('toEndWith', 1, [0], 2, true),
            'assertjson' => new AssertionConversion('toBeJson', 0, [], 1, false),
            'assertjsonstringequalsjsonstring' => new AssertionConversion('toMatchJson', 1, [0], 2, false),
        ];

        return $entries[\strtolower($method)] ?? null;
    }
}
