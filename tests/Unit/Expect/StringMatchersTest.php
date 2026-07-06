<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class StringMatchersTest
{
    #[Test]
    public function toMatchPasses(): void
    {
        new Expect()->that('greenlight-42')->toMatch('/\d+/');
    }

    #[Test]
    public function toMatchFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that('abc')->toMatch('/\d+/'),
        );

        $expect = new Expect();
        $expect->that($detail->message)->toBe("Expected 'abc' to match /\\d+/.");
        $expect->that($detail->expected)->toBe('/\d+/');
    }

    #[Test]
    public function notToMatch(): void
    {
        new Expect()->that('abc')->not()->toMatch('/\d+/');
    }

    #[Test]
    public function toMatchGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(123)->toMatch('/\d+/'),
        );

        new Expect()->that($detail->message)->toBe('toMatch() requires a string subject, got int.');
    }

    #[Test]
    public function toMatchRejectsInvalidPatterns(): void
    {
        new Expect()
            ->that(static fn() => new Expect()->that('abc')->toMatch('not a pattern'))
            ->toThrow(\InvalidArgumentException::class, matching: '/invalid regular expression/');
    }

    #[Test]
    public function toStartWithPasses(): void
    {
        new Expect()->that('greenlight')->toStartWith('green');
        new Expect()->that('greenlight')->toStartWith('');
    }

    #[Test]
    public function toStartWithFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that('greenlight')->toStartWith('light'),
        );

        new Expect()->that($detail->message)->toBe("Expected 'greenlight' to start with 'light'.");
    }

    #[Test]
    public function notToStartWith(): void
    {
        new Expect()->that('greenlight')->not()->toStartWith('light');
    }

    #[Test]
    public function toStartWithGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(['green'])->toStartWith('green'),
        );

        new Expect()->that($detail->message)->toBe('toStartWith() requires a string subject, got array.');
    }

    #[Test]
    public function toEndWithPasses(): void
    {
        new Expect()->that('greenlight')->toEndWith('light');
    }

    #[Test]
    public function toEndWithFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that('greenlight')->toEndWith('green'),
        );

        new Expect()->that($detail->message)->toBe("Expected 'greenlight' to end with 'green'.");
    }

    #[Test]
    public function notToEndWith(): void
    {
        new Expect()->that('greenlight')->not()->toEndWith('green');
    }

    #[Test]
    public function toEndWithGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(null)->toEndWith('x'),
        );

        new Expect()->that($detail->message)->toBe('toEndWith() requires a string subject, got null.');
    }
}
