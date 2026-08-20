<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\Cleanup;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Tests\Fixture\Expect\EvenNumbersExtension;

final readonly class ExpectTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function notAppliesOnlyToTheNextMatcher(): void
    {
        Expect::that(1)->because('not() applies only to the next matcher')->not()->toBe(2)->toBe(1);
    }

    #[Test]
    public function chainingContinuesAfterAPassingMatcher(): void
    {
        Expect::that('greenlight')->because('chaining continues after a passing matcher')->toStartWith('green')->toEndWith('light')->toContain('nli');
    }

    #[Test]
    public function failureLocationPointsAtTheCallSite(): void
    {
        $line = __LINE__ + 1;
        $detail = FailureProbe::detailOf(static fn() => Expect::that(1)->toBe(2));

        Expect::that($detail->location?->file)->because('failure location points at the call site')->toBe(__FILE__);
        Expect::that($detail->location?->line)->because('failure location points at the call site')->toBe($line);
    }

    #[Test]
    public function singleFailureMessageCarriesTheLocation(): void
    {
        Expect::that(static fn() => Expect::that(1)->toBe(2))
            ->toThrow(static function (ExpectationFailed $failure): void {
                Expect::that($failure->getMessage())->toContain('Expected 1 to be 2. (at ' . __FILE__ . ':');
            });
    }

    #[Test]
    public function installedExtensionsAreDispatchedByChains(): void
    {
        $restoreExtensions = Expect::install([new EvenNumbersExtension()]);
        $this->cleanup->defer($restoreExtensions);

        Expect::that(4)->toBeEven();
        Expect::that(3)->not()->toBeEven();
    }

    #[Test]
    public function chainsCreatedBeforeAnInstallKeepTheirExtensions(): void
    {
        Expect::install([new EvenNumbersExtension()]);
        $chain = Expect::that(4);
        Expect::install([]);

        $chain->toBeEven();

        Expect::that(static fn() => Expect::that(4)->toBeEven())->because('chains created before an install keep their extensions')
            ->toThrow(\BadMethodCallException::class);
    }

    #[Test]
    public function installReturnsCleanupThatRestoresThePreviousExtensions(): void
    {
        $restoreEmpty = Expect::install([new EvenNumbersExtension()]);
        $restoreEvenNumbers = Expect::install([]);

        $restoreEvenNumbers();
        $chain = Expect::that(4);
        $restoreEmpty();

        $chain->toBeEven();
        Expect::that(static fn() => Expect::that(4)->toBeEven())
            ->because('install cleanup restores the exact previous extension list')
            ->toThrow(\BadMethodCallException::class);
    }
}
