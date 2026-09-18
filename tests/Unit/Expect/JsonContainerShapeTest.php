<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;

use function Greenlight\expect;

final class JsonContainerShapeTest
{
    #[Test]
    public function emptyObjectsDifferFromEmptyArrays(): void
    {
        expect('{}')->not()->toMatchJson('[]');
        expect('[]')->not()->toMatchJson('{}');
    }

    #[Test]
    public function numericObjectKeysDoNotBecomeArrayIndices(): void
    {
        expect('{"0":"first","1":"second"}')
            ->not()->toMatchJson('["first","second"]');
    }

    #[Test]
    public function nestedContainersPreserveTheirTypes(): void
    {
        expect('{"items":{}}')->not()->toMatchJson('{"items":[]}');
        expect('[{}]')->not()->toMatchJson('[[]]');
    }

    #[Test]
    public function objectKeyOrderDoesNotAffectEquality(): void
    {
        expect('{"items":[],"options":{}}')
            ->toMatchJson('{"options":{},"items":[]}');
    }

    #[Test]
    public function validNullCharacterKeysRetainTheirNamesAndContainerShapes(): void
    {
        expect('{"\u0000name":{}}')->toMatchJson('{"\u0000name":{}}');
        expect('{"\u0000name":{}}')->not()->toMatchJson('{"\u0000name":[]}');
        expect('{"\u0000name":1}')->not()->toMatchJson('{"name":1}');
        expect('{"\u0000name":1}')->not()->toMatchJson('{"_\u0000name":1}');
    }

    #[Test]
    public function escapedStringsAndDuplicateKeysKeepNativeJsonSemantics(): void
    {
        expect('{"quote":"\"","slash":"\\\\","brackets":"[{}]"}')
            ->toMatchJson('{"brackets":"[{}]","slash":"\u005c","quote":"\u0022"}');
        expect('{"name":1,"\u006eame":2}')->toMatchJson('{"name":2}');
        expect('""')->not()->toMatchJson('"_"');
        expect('"0"')->not()->toMatchJson('0');
    }

    #[Test]
    public function validDeepContainersKeepTheNativeDepthLimit(): void
    {
        $json = \str_repeat('[', 511) . '"value"' . \str_repeat(']', 511);

        expect($json)->not()->toMatchJson('[]');
        expect('[]')->not()->toMatchJson($json);
    }

    #[Test]
    public function invalidEscapesAndExcessiveDepthRemainUsageErrors(): void
    {
        foreach ([
            '"unterminated',
            '"\q"',
            '"\uD800"',
            '"first" "second"',
            "\"line\nbreak\"",
            \str_repeat('[', 512) . '0' . \str_repeat(']', 512),
        ] as $invalid) {
            $detail = FailureProbe::detailOf(
                static fn() => expect('{}')->toMatchJson($invalid),
            );

            expect($detail->message)->toBe('Pass valid JSON as the expected value to toMatchJson().');
        }
    }
}
