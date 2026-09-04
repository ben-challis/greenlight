<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class JsonContainerShapeTest
{
    #[Test]
    public function emptyObjectsDifferFromEmptyArrays(): void
    {
        Expect::that('{}')->not()->toMatchJson('[]');
        Expect::that('[]')->not()->toMatchJson('{}');
    }

    #[Test]
    public function numericObjectKeysDoNotBecomeArrayIndices(): void
    {
        Expect::that('{"0":"first","1":"second"}')
            ->not()->toMatchJson('["first","second"]');
    }

    #[Test]
    public function nestedContainersPreserveTheirTypes(): void
    {
        Expect::that('{"items":{}}')->not()->toMatchJson('{"items":[]}');
        Expect::that('[{}]')->not()->toMatchJson('[[]]');
    }

    #[Test]
    public function objectKeyOrderDoesNotAffectEquality(): void
    {
        Expect::that('{"items":[],"options":{}}')
            ->toMatchJson('{"options":{},"items":[]}');
    }

    #[Test]
    public function validNullCharacterKeysRetainTheirNamesAndContainerShapes(): void
    {
        Expect::that('{"\u0000name":{}}')->toMatchJson('{"\u0000name":{}}');
        Expect::that('{"\u0000name":{}}')->not()->toMatchJson('{"\u0000name":[]}');
        Expect::that('{"\u0000name":1}')->not()->toMatchJson('{"name":1}');
        Expect::that('{"\u0000name":1}')->not()->toMatchJson('{"_\u0000name":1}');
    }

    #[Test]
    public function escapedStringsAndDuplicateKeysKeepNativeJsonSemantics(): void
    {
        Expect::that('{"quote":"\"","slash":"\\\\","brackets":"[{}]"}')
            ->toMatchJson('{"brackets":"[{}]","slash":"\u005c","quote":"\u0022"}');
        Expect::that('{"name":1,"\u006eame":2}')->toMatchJson('{"name":2}');
        Expect::that('""')->not()->toMatchJson('"_"');
        Expect::that('"0"')->not()->toMatchJson('0');
    }

    #[Test]
    public function validDeepContainersKeepTheNativeDepthLimit(): void
    {
        $json = \str_repeat('[', 511) . '"value"' . \str_repeat(']', 511);

        Expect::that($json)->not()->toMatchJson('[]');
        Expect::that('[]')->not()->toMatchJson($json);
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
                static fn() => Expect::that('{}')->toMatchJson($invalid),
            );

            Expect::that($detail->message)->toBe('Pass valid JSON as the expected value to toMatchJson().');
        }
    }
}
