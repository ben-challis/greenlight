<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class JsonMatchersTest
{
    #[Test]
    public function toBeJsonPasses(): void
    {
        Expect::value('{"a": 1}')->because('toBeJson() passes')->toBeJson();
        Expect::value('[1, 2]')->because('toBeJson() passes')->toBeJson();
        Expect::value('"x"')->because('toBeJson() passes')->toBeJson();
        Expect::value('null')->because('toBeJson() passes')->toBeJson();
    }

    #[Test]
    public function toBeJsonFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value('{oops')->toBeJson(),
        );

        Expect::value($detail->message)->because('toBeJson() fails')->toBe("Expected '{oops' to be valid JSON.");
        Expect::value($detail->expected)->because('toBeJson() fails')->toBe('valid JSON');
    }

    #[Test]
    public function notToBeJson(): void
    {
        Expect::value('{oops')->because('not()->toBe() JSON')->not()->toBeJson();
    }

    #[Test]
    public function toBeJsonGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value([])->toBeJson(), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::value($detail->message)->because('toBeJson() guards the subject type')
            ->toBe('toBeJson() requires a string subject. The subject type is array.');
    }

    #[Test]
    public function toMatchJsonIgnoresObjectKeyOrder(): void
    {
        Expect::value('{"a": 1, "b": [1, 2]}')->because('toMatchJson() ignores object key order')->toMatchJson('{"b": [1, 2], "a": 1}');
    }

    #[Test]
    public function toMatchJsonFailsOnStructuralMismatch(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value('{"a": 2}')->toMatchJson('{"a": 1}'),
        );

        Expect::value($detail->message)->because('toMatchJson() fails on structural mismatch')->toBe('Expected \'{"a": 2}\' to match the JSON structure \'{"a": 1}\'.');
        Expect::value($detail->expected)->because('toMatchJson() fails on structural mismatch')->toBe('\'{"a": 1}\'');
        Expect::value($detail->actual)->because('toMatchJson() fails on structural mismatch')->toBe('\'{"a": 2}\'');
    }

    #[Test]
    public function toMatchJsonFailsDistinctlyOnInvalidSubjectJson(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value('nope')->toMatchJson('{"a": 1}'),
        );

        Expect::value($detail->message)->because('toMatchJson() fails distinctly on invalid subject JSON')->toBe('Expected \'nope\' to be valid JSON matching \'{"a": 1}\'.');
    }

    #[Test]
    public function notToMatchJson(): void
    {
        Expect::value('{"a": 2}')->because('not()->toMatch() JSON')->not()->toMatchJson('{"a": 1}');
        Expect::value('nope')->because('not()->toMatch() JSON')->not()->toMatchJson('{"a": 1}');
    }

    #[Test]
    public function toMatchJsonGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value(1)->toMatchJson('{}'), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::value($detail->message)->because('toMatchJson() guards the subject type')
            ->toBe('toMatchJson() requires a string subject. The subject type is int.');
    }

    #[Test]
    public function toMatchJsonGuardsTheExpectedValue(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value('{}')->toMatchJson('{oops'), // @phpstan-ignore greenlight.expectationArgument.json (deliberately invalid: tests runtime validation)
        );

        Expect::value($detail->message)->because('toMatchJson() guards the expected value')
            ->toBe('Pass valid JSON as the expected value to toMatchJson().');
    }
}
