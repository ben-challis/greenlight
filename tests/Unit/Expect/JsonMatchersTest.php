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
        Expect::that('{"a": 1}')->toBeJson();
        Expect::that('[1, 2]')->toBeJson();
        Expect::that('"x"')->toBeJson();
        Expect::that('null')->toBeJson();
    }

    #[Test]
    public function toBeJsonFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('{oops')->toBeJson(),
        );

        Expect::that($detail->message)->toBe("Expected '{oops' to be valid JSON.");
        Expect::that($detail->expected)->toBe('valid JSON');
    }

    #[Test]
    public function notToBeJson(): void
    {
        Expect::that('{oops')->not()->toBeJson();
    }

    #[Test]
    public function toBeJsonGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that([])->toBeJson(),
        );

        Expect::that($detail->message)->toBe('toBeJson() requires a string subject, got array.');
    }

    #[Test]
    public function toMatchJsonPasses(): void
    {
        Expect::that('{"a": 1}')->toMatchJson('{"a": 1}');
    }

    #[Test]
    public function toMatchJsonIgnoresObjectKeyOrder(): void
    {
        Expect::that('{"a": 1, "b": [1, 2]}')->toMatchJson('{"b": [1, 2], "a": 1}');
    }

    #[Test]
    public function toMatchJsonFailsOnStructuralMismatch(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('{"a": 2}')->toMatchJson('{"a": 1}'),
        );

        Expect::that($detail->message)->toBe("Expected ['a' => 2] to match the JSON structure ['a' => 1].");
        Expect::that($detail->expected)->toBe("['a' => 1]");
        Expect::that($detail->actual)->toBe("['a' => 2]");
    }

    #[Test]
    public function toMatchJsonFailsDistinctlyOnInvalidSubjectJson(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('nope')->toMatchJson('{"a": 1}'),
        );

        Expect::that($detail->message)->toBe("Expected 'nope' to be valid JSON matching ['a' => 1].");
    }

    #[Test]
    public function notToMatchJson(): void
    {
        Expect::that('{"a": 2}')->not()->toMatchJson('{"a": 1}');
        Expect::that('nope')->not()->toMatchJson('{"a": 1}');
    }

    #[Test]
    public function toMatchJsonGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(1)->toMatchJson('{}'),
        );

        Expect::that($detail->message)->toBe('toMatchJson() requires a string subject, got int.');
    }

    #[Test]
    public function toMatchJsonGuardsTheExpectedValue(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('{}')->toMatchJson('{oops'),
        );

        Expect::that($detail->message)->toBe('toMatchJson() requires valid JSON as the expected value.');
    }
}
