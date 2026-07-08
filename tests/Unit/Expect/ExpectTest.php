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
        Expect::that(1)->toBe(1)->and('x')->toBe('x')->and([1])->toHaveCount(1);
    }

    #[Test]
    public function notAppliesOnlyToTheNextMatcher(): void
    {
        Expect::that(1)->not()->toBe(2)->toBe(1);
        Expect::that(1)->not()->toBe(2)->and(3)->toBe(3);
    }

    #[Test]
    public function chainingContinuesAfterAPassingMatcher(): void
    {
        Expect::that('greenlight')->toStartWith('green')->toEndWith('light')->toContain('nli');
    }

    #[Test]
    public function failureLocationPointsAtTheCallSite(): void
    {
        $line = __LINE__ + 1;
        $detail = FailureProbe::detailOf(static fn() => Expect::that(1)->toBe(2));

        Expect::that($detail->location?->file)->toBe(__FILE__);
        Expect::that($detail->location?->line)->toBe($line);
    }

    #[Test]
    public function singleFailureMessageCarriesTheLocation(): void
    {
        try {
            Expect::that(1)->toBe(2);
        } catch (ExpectationFailed $failure) {
            Expect::that($failure->getMessage())->toContain('Expected 1 to be 2. (at ' . __FILE__ . ':');

            return;
        }

        throw new \RuntimeException('The expectation should have failed.');
    }

    #[Test]
    public function installedExtensionsAreDispatchedByChains(): void
    {
        Expect::install([new EvenNumbersExtension()]);

        try {
            // Dispatched through __call directly: static analysis only knows
            // the matchers declared in configured greenlight.php files.
            Expect::that(4)->__call('toBeEven', []);
            Expect::that(3)->not()->__call('toBeEven', []);
        } finally {
            Expect::install([]);
        }
    }

    #[Test]
    public function chainsCreatedBeforeAnInstallKeepTheirExtensions(): void
    {
        Expect::install([new EvenNumbersExtension()]);
        $chain = Expect::that(4);
        Expect::install([]);

        $chain->__call('toBeEven', []);

        Expect::that(static fn() => Expect::that(4)->__call('toBeEven', []))
            ->toThrow(\BadMethodCallException::class);
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
