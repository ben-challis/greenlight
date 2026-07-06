<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationExtension;
use Greenlight\Expect\ExpectationFailed;

final class ExpectTest
{
    #[Test]
    public function andReAnchorsTheChainOnANewSubject(): void
    {
        new Expect()->that(1)->toBe(1)->and('x')->toBe('x')->and([1])->toHaveCount(1);
    }

    #[Test]
    public function notAppliesOnlyToTheNextMatcher(): void
    {
        new Expect()->that(1)->not()->toBe(2)->toBe(1);
        new Expect()->that(1)->not()->toBe(2)->and(3)->toBe(3);
    }

    #[Test]
    public function chainingContinuesAfterAPassingMatcher(): void
    {
        new Expect()->that('greenlight')->toStartWith('green')->toEndWith('light')->toContain('nli');
    }

    #[Test]
    public function failureLocationPointsAtTheCallSite(): void
    {
        $line = __LINE__ + 1;
        $detail = FailureProbe::detailOf(static fn() => new Expect()->that(1)->toBe(2));

        $expect = new Expect();
        $expect->that($detail->location?->file)->toBe(__FILE__);
        $expect->that($detail->location?->line)->toBe($line);
    }

    #[Test]
    public function singleFailureMessageCarriesTheLocation(): void
    {
        try {
            new Expect()->that(1)->toBe(2);
        } catch (ExpectationFailed $failure) {
            new Expect()->that($failure->getMessage())->toContain('Expected 1 to be 2. (at ' . __FILE__ . ':');

            return;
        }

        throw new \RuntimeException('The expectation should have failed.');
    }

    #[Test]
    public function softlyCollectsEveryFailureAndThrowsOneAggregate(): void
    {
        $expect = new Expect();

        try {
            $expect->softly(static function (Expect $soft): void {
                $soft->that(1)->toBe(2);
                $soft->that('a')->toBe('a');
                $soft->that(true)->toBeFalse();
            });
        } catch (ExpectationFailed $failure) {
            $expect->that($failure->details)->toHaveCount(2);
            $expect->that($failure->details[0]->message)->toBe('Expected 1 to be 2.');
            $expect->that($failure->details[1]->message)->toBe('Expected true to be false.');
            $expect->that($failure->getMessage())->toStartWith('2 expectations failed:');
            $expect->that($failure->getMessage())->toContain('1) Expected 1 to be 2.');
            $expect->that($failure->getMessage())->toContain('2) Expected true to be false.');

            return;
        }

        throw new \RuntimeException('softly() should have thrown an aggregate failure.');
    }

    #[Test]
    public function softlyDoesNotThrowWhenEverythingPasses(): void
    {
        new Expect()->softly(static function (Expect $soft): void {
            $soft->that(1)->toBe(1);
            $soft->that('a')->not()->toBe('b');
        });
    }

    #[Test]
    public function softlyWithASingleFailureThrowsWithoutTheAggregateHeader(): void
    {
        try {
            new Expect()->softly(static function (Expect $soft): void {
                $soft->that(1)->toBe(2);
            });
        } catch (ExpectationFailed $failure) {
            $expect = new Expect();
            $expect->that($failure->details)->toHaveCount(1);
            $expect->that($failure->getMessage())->toStartWith('Expected 1 to be 2.');

            return;
        }

        throw new \RuntimeException('softly() should have thrown.');
    }

    #[Test]
    public function softlyLeavesTheOuterInstanceFailingFast(): void
    {
        $expect = new Expect();

        $expect->softly(static function (Expect $soft): void {
            $soft->that(1)->toBe(1);
        });

        new Expect()
            ->that(static fn() => $expect->that(1)->toBe(2))
            ->toThrow(ExpectationFailed::class);
    }

    #[Test]
    public function extensionsAreRegisteredOnConstruction(): void
    {
        $extension = new EvenNumbersExtension();
        $expect = new Expect([$extension]);

        $expect->that($expect->extensions())->toBe([$extension]);
        $expect->that(new Expect()->extensions())->toBe([]);

        $matchers = $extension->matchers();
        $expect->that($matchers)->toHaveKey('toBeEven');
        $expect->that($matchers['toBeEven'](4))->toBeTrue();
        $expect->that($matchers['toBeEven'](3))->toBeFalse();
    }
}

final class EvenNumbersExtension implements ExpectationExtension
{
    #[\Override]
    public function matchers(): array
    {
        return [
            'toBeEven' => static fn(mixed $subject): bool => \is_int($subject) && $subject % 2 === 0,
        ];
    }
}
