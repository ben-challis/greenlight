<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Attribute;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Group;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Skip;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Expect\Expect;

final class AttributeContractTest
{
    #[Test]
    public function skipRejectsAnEmptyReason(): void
    {
        Expect::that(static fn(): object => new \ReflectionClass(Skip::class)->newInstance(''))
            ->because('skip reasons cannot be empty')
            ->toThrow(\InvalidArgumentException::class, message: 'Skip reasons cannot be empty.');
    }

    #[Test]
    public function skipPreservesAZeroStringReason(): void
    {
        Expect::that(new Skip('0')->reason)
            ->because('the skip attribute MUST preserve a zero-string reason')
            ->toBe('0');
    }

    #[Test]
    public function dataSetAcceptsLocalAndExternalProviders(): void
    {
        $local = new DataSet('rows');
        $external = new DataSet(self::class, 'rows');

        Expect::that($local->provider)->because('data set accepts local and external providers')->toBe('rows');
        Expect::that($local->providerClass)->toBeNull();
        Expect::that($external->provider)->toBe('rows');
        Expect::that($external->providerClass)->toBe(self::class);
    }

    #[Test]
    public function groupRejectsAnEmptyName(): void
    {
        Expect::that(static fn(): object => new \ReflectionClass(Group::class)->newInstance(''))
            ->because('group names cannot be empty')
            ->toThrow(\InvalidArgumentException::class, message: 'Group names cannot be empty.');
    }

    #[Test]
    public function groupPreservesAZeroStringName(): void
    {
        Expect::that(new Group('0')->name)
            ->because('the group attribute MUST preserve a zero-string name')
            ->toBe('0');
    }

    #[Test]
    public function resourceRequirementsRejectNonCanonicalNames(): void
    {
        foreach (['', 'Postgres', 'postgres primary', '-postgres'] as $name) {
            Expect::that(static fn(): object => new \ReflectionClass(RequiresResource::class)->newInstance($name))
                ->toThrow(\InvalidArgumentException::class);
        }
    }

    #[Test]
    public function retryRejectsZeroTimes(): void
    {
        Expect::that(static fn(): Retry => new Retry(0))->because('retry rejects zero times')->toThrow(\InvalidArgumentException::class);
    }

    #[Test]
    public function timeoutRejectsNonPositiveSeconds(): void
    {
        Expect::that(static fn(): Timeout => new Timeout(0.0))->because('timeout rejects nonpositive seconds')->toThrow(\InvalidArgumentException::class); // @phpstan-ignore greenlight.timeoutConstructor.seconds (deliberately invalid: tests runtime validation)
        Expect::that(static fn(): Timeout => new Timeout(-1.5))->because('timeout rejects nonpositive seconds')->toThrow(\InvalidArgumentException::class); // @phpstan-ignore greenlight.timeoutConstructor.seconds (deliberately invalid: tests runtime validation)
    }

    #[Test]
    public function timeoutRejectsNonfiniteSeconds(): void
    {
        Expect::that(static fn(): Timeout => new Timeout(\NAN))->because('timeout rejects nonfinite seconds')->toThrow(\InvalidArgumentException::class); // @phpstan-ignore greenlight.timeoutConstructor.seconds (deliberately invalid: tests runtime validation)
        Expect::that(static fn(): Timeout => new Timeout(\INF))->because('timeout rejects nonfinite seconds')->toThrow(\InvalidArgumentException::class); // @phpstan-ignore greenlight.timeoutConstructor.seconds (deliberately invalid: tests runtime validation)
    }
}
