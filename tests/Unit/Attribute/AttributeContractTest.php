<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Attribute;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Group;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\NoExpectations;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Skip;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Expect\Expect;

final class AttributeContractTest
{
    #[Test]
    public function methodOnlyAttributesTargetMethods(): void
    {
        foreach ([Test::class, Before::class, After::class, DataSet::class, NoExpectations::class] as $attribute) {
            Expect::that($this->flags($attribute))->toBe(\Attribute::TARGET_METHOD);
        }
    }

    #[Test]
    public function inlineDataRowsAreRepeatableOnMethods(): void
    {
        Expect::that($this->flags(DataRow::class))
            ->because('inline data rows MUST be repeatable on methods')
            ->toBe(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE);
    }

    #[Test]
    public function dataSetAcceptsLocalAndExternalProviders(): void
    {
        $local = new DataSet('rows');
        $external = new DataSet(self::class, 'rows');

        Expect::that($local->provider)->because('data set accepts local and external providers')->toBe('rows')
            ->and($local->providerClass)->toBeNull()
            ->and($external->provider)->toBe('rows')
            ->and($external->providerClass)->toBe(self::class);
    }

    #[Test]
    #[DataSet('emptyDataSetProviderNames')]
    public function dataSetRejectsEmptyProviderNames(string $provider, ?string $method): void
    {
        Expect::that(static fn(): DataSet => new DataSet($provider, $method))
            ->because('a data-set provider name MUST NOT be empty')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Data-set provider names cannot be empty.',
            );
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function emptyDataSetProviderNames(): iterable
    {
        yield 'local provider' => ['', null];
        yield 'external provider class' => ['', 'rows'];
        yield 'external provider method' => [self::class, ''];
    }

    #[Test]
    public function methodOrClassAttributesTargetBoth(): void
    {
        foreach ([Skip::class, SkipUnless::class, Retry::class, Timeout::class, Isolated::class] as $attribute) {
            Expect::that($this->flags($attribute))->toBe(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS);
        }
    }

    #[Test]
    public function groupIsRepeatableOnMethodsAndClasses(): void
    {
        Expect::that($this->flags(Group::class))->because('group is repeatable on methods and classes')
            ->toBe(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE);
    }

    #[Test]
    public function resourceRequirementsAreRepeatableOnMethodsAndClasses(): void
    {
        Expect::that($this->flags(RequiresResource::class))->because('resource requirements are repeatable on methods and classes')
            ->toBe(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE);
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
    public function coverageIgnoreTargetsClassesMethodsAndFunctions(): void
    {
        Expect::that($this->flags(CoverageIgnore::class))->because('coverage ignore targets classes methods and functions')
            ->toBe(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION);
    }

    #[Test]
    public function retryRejectsZeroTimes(): void
    {
        Expect::that(static fn(): Retry => new Retry(0))->because('retry rejects zero times')->toThrow(\InvalidArgumentException::class);
    }

    #[Test]
    public function timeoutRejectsNonPositiveSeconds(): void
    {
        Expect::that(static fn(): Timeout => new Timeout(0.0))->because('timeout rejects nonpositive seconds')->toThrow(\InvalidArgumentException::class);
        Expect::that(static fn(): Timeout => new Timeout(-1.5))->because('timeout rejects nonpositive seconds')->toThrow(\InvalidArgumentException::class);
    }

    #[Test]
    public function timeoutRejectsNonfiniteSeconds(): void
    {
        Expect::that(static fn(): Timeout => new Timeout(\NAN))->because('timeout rejects nonfinite seconds')->toThrow(\InvalidArgumentException::class);
        Expect::that(static fn(): Timeout => new Timeout(\INF))->because('timeout rejects nonfinite seconds')->toThrow(\InvalidArgumentException::class);
    }

    /**
     * @param class-string $attributeClass
     */
    private function flags(string $attributeClass): int
    {
        $attributes = new \ReflectionClass($attributeClass)->getAttributes(\Attribute::class);
        Expect::that($attributes)->toHaveCount(1);

        return $attributes[0]->newInstance()->flags;
    }
}
