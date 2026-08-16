<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\Fail;
use Greenlight\Tests\Fixture\Expect\EvenNumbersExtension;

final class ExpectTest
{
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
        try {
            Expect::that(1)->toBe(2);
        } catch (ExpectationFailed $failure) {
            Expect::that($failure->getMessage())->toContain('Expected 1 to be 2. (at ' . __FILE__ . ':');

            return;
        }

        Fail::because('Expected Expect::that(1)->toBe(2) to throw ExpectationFailed.');
    }

    #[Test]
    public function installedExtensionsAreDispatchedByChains(): void
    {
        Expect::install([new EvenNumbersExtension()]);

        try {
            // Dispatch directly through __call. Static analysis knows only the
            // matchers in configured greenlight.php files.
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

        Expect::that(static fn() => Expect::that(4)->__call('toBeEven', []))->because('chains created before an install keep their extensions')
            ->toThrow(\BadMethodCallException::class);
    }
}
