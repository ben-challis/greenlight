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
        Expect::value('{}')->not()->toMatchJson('[]');
        Expect::value('[]')->not()->toMatchJson('{}');
    }

    #[Test]
    public function numericObjectKeysDoNotBecomeArrayIndices(): void
    {
        Expect::value('{"0":"first","1":"second"}')
            ->not()->toMatchJson('["first","second"]');
    }

    #[Test]
    public function nestedContainersPreserveTheirTypes(): void
    {
        Expect::value('{"items":{}}')->not()->toMatchJson('{"items":[]}');
        Expect::value('[{}]')->not()->toMatchJson('[[]]');
    }

    #[Test]
    public function objectKeyOrderDoesNotAffectEquality(): void
    {
        Expect::value('{"items":[],"options":{}}')
            ->toMatchJson('{"options":{},"items":[]}');
    }

    #[Test]
    public function validNullCharacterKeysRetainTheirNamesAndContainerShapes(): void
    {
        Expect::value('{"\u0000name":{}}')->toMatchJson('{"\u0000name":{}}');
        Expect::value('{"\u0000name":{}}')->not()->toMatchJson('{"\u0000name":[]}');
        Expect::value('{"\u0000name":1}')->not()->toMatchJson('{"name":1}');
        Expect::value('{"\u0000name":1}')->not()->toMatchJson('{"_\u0000name":1}');
    }

    #[Test]
    public function escapedStringsAndDuplicateKeysKeepNativeJsonSemantics(): void
    {
        Expect::value('{"quote":"\"","slash":"\\\\","brackets":"[{}]"}')
            ->toMatchJson('{"brackets":"[{}]","slash":"\u005c","quote":"\u0022"}');
        Expect::value('{"name":1,"\u006eame":2}')->toMatchJson('{"name":2}');
        Expect::value('""')->not()->toMatchJson('"_"');
        Expect::value('"0"')->not()->toMatchJson('0');
    }

    #[Test]
    public function validDeepContainersKeepTheNativeDepthLimit(): void
    {
        $json = \str_repeat('[', 511) . '"value"' . \str_repeat(']', 511);

        Expect::value($json)->not()->toMatchJson('[]');
        Expect::value('[]')->not()->toMatchJson($json);
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
                static fn() => Expect::value('{}')->toMatchJson($invalid),
            );

            Expect::value($detail->message)->toBe('Pass valid JSON as the expected value to toMatchJson().');
        }
    }
}
