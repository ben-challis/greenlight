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

use function Greenlight\expect;

final class AttributeContractTest
{
    #[Test]
    public function skipRejectsAnEmptyReason(): void
    {
        expect()->calling(static fn(): object => new \ReflectionClass(Skip::class)->newInstance(''))
            ->because('skip reasons cannot be empty')
            ->toThrow(\InvalidArgumentException::class, message: 'Skip reasons cannot be empty.');
    }

    #[Test]
    public function skipPreservesAZeroStringReason(): void
    {
        expect(new Skip('0')->reason)
            ->because('the skip attribute MUST preserve a zero-string reason')
            ->toBe('0');
    }

    #[Test]
    public function dataSetAcceptsLocalAndExternalProviders(): void
    {
        $local = new DataSet('rows');
        $external = new DataSet(self::class, 'rows');

        expect($local->provider)->because('data set accepts local and external providers')->toBe('rows');
        expect($local->providerClass)->toBeNull();
        expect($external->provider)->toBe('rows');
        expect($external->providerClass)->toBe(self::class);
    }

    #[Test]
    public function groupRejectsAnEmptyName(): void
    {
        expect()->calling(static fn(): object => new \ReflectionClass(Group::class)->newInstance(''))
            ->because('group names cannot be empty')
            ->toThrow(\InvalidArgumentException::class, message: 'Group names cannot be empty.');
    }

    #[Test]
    public function groupPreservesAZeroStringName(): void
    {
        expect(new Group('0')->name)
            ->because('the group attribute MUST preserve a zero-string name')
            ->toBe('0');
    }

    #[Test]
    public function resourceRequirementsRejectNonCanonicalNames(): void
    {
        foreach (['', 'Postgres', 'postgres primary', '-postgres'] as $name) {
            expect()->calling(static fn(): object => new \ReflectionClass(RequiresResource::class)->newInstance($name))
                ->toThrow(\InvalidArgumentException::class);
        }
    }

    #[Test]
    public function retryRejectsZeroTimes(): void
    {
        expect()->calling(static fn(): Retry => new Retry(0))->because('retry rejects zero times')->toThrow(\InvalidArgumentException::class);
    }

    #[Test]
    public function timeoutRejectsNonPositiveSeconds(): void
    {
        expect()->calling(static fn(): Timeout => new Timeout(0.0))->because('timeout rejects nonpositive seconds')->toThrow(\InvalidArgumentException::class); // @phpstan-ignore greenlight.timeoutConstructor.seconds (deliberately invalid: tests runtime validation)
        expect()->calling(static fn(): Timeout => new Timeout(-1.5))->because('timeout rejects nonpositive seconds')->toThrow(\InvalidArgumentException::class); // @phpstan-ignore greenlight.timeoutConstructor.seconds (deliberately invalid: tests runtime validation)
    }

    #[Test]
    public function timeoutRejectsNonfiniteSeconds(): void
    {
        expect()->calling(static fn(): Timeout => new Timeout(\NAN))->because('timeout rejects nonfinite seconds')->toThrow(\InvalidArgumentException::class); // @phpstan-ignore greenlight.timeoutConstructor.seconds (deliberately invalid: tests runtime validation)
        expect()->calling(static fn(): Timeout => new Timeout(\INF))->because('timeout rejects nonfinite seconds')->toThrow(\InvalidArgumentException::class); // @phpstan-ignore greenlight.timeoutConstructor.seconds (deliberately invalid: tests runtime validation)
    }
}
