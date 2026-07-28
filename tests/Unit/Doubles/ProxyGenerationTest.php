<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\CacheAlpha;
use Greenlight\Tests\Fixture\Doubles\CacheBeta;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\Clock;
use Greenlight\Tests\Fixture\Doubles\DestructorProbe;
use Greenlight\Tests\Fixture\Doubles\PropertyContract;
use Greenlight\Tests\Fixture\Doubles\ProxyFileProbe;
use Greenlight\Tests\Fixture\Doubles\SelfConstantDefault;
use Greenlight\Tests\Fixture\Doubles\StaticMethodFixture;
use Greenlight\Tests\Fixture\Doubles\Wide;

final class ProxyGenerationTest
{
    #[Test]
    public function theSameTypeReusesTheGeneratedClass(): void
    {
        $doubles = new Doubles();
        $first = $doubles->spy(Calculator::class);
        $second = $doubles->spy(Calculator::class);

        Expect::that($second::class)->because('the same type reuses the generated class')->toBe($first::class);

        $doubles->dispose();
    }

    #[Test]
    public function differentSignaturesGenerateDifferentClasses(): void
    {
        $doubles = new Doubles();
        $alpha = $doubles->spy(CacheAlpha::class);
        $beta = $doubles->spy(CacheBeta::class);

        Expect::that($alpha::class)->because('different signatures generate different classes')->not()->toBe($beta::class);

        $doubles->dispose();
    }

    #[Test]
    public function theDefaultCacheLivesInTheSystemTempDirKeyedByWorkingDirectory(): void
    {
        $workingDirectory = \getcwd();
        \assert($workingDirectory !== false);

        $directory = \sprintf(
            '%s/greenlight-proxies-%s',
            \rtrim(\sys_get_temp_dir(), '/'),
            \substr(\sha1($workingDirectory), 0, 12),
        );

        // The proxy class name contains a hash of Calculator signatures. Thus,
        // its generated file has a deterministic name. Other tests can leave
        // this file in the cache, so start with an empty cache.
        $expectedFile = null;
        $doubles = new Doubles();

        try {
            $proxyClass = $doubles->spy(Calculator::class)::class;
            $separator = \strrpos($proxyClass, '\\');
            \assert($separator !== false);
            $shortName = \substr($proxyClass, $separator + 1);
            $expectedFile = $directory . '/' . $shortName . '.php';

            Expect::that(\is_file($expectedFile))->toBeTrue();
        } finally {
            $doubles->dispose();

            if ($expectedFile !== null) {
                @\unlink($expectedFile);
            }
        }
    }

    #[Test]
    public function theProxyFileIsWrittenOnceAndReused(): void
    {
        $directory = \sys_get_temp_dir() . '/greenlight-doubles-' . \bin2hex(\random_bytes(6));
        $doubles = new Doubles($directory);

        $doubles->spy(ProxyFileProbe::class);
        $doubles->spy(ProxyFileProbe::class);

        $files = \glob($directory . '/*.php');

        Expect::that($files === false ? [] : $files)->because('the proxy file is written once and reused')->toHaveCount(1);

        $doubles->dispose();
        $this->removeDirectory($directory);
    }

    #[Test]
    public function classDoublesNeverRunTheDoubledConstructor(): void
    {
        // The Clock constructor throws. Creation of the double without an
        // exception shows that Greenlight did not run the constructor.
        $doubles = new Doubles();
        $clock = $doubles->stub(Clock::class);

        Expect::that($clock)->because('class doubles never run the doubled constructor')->toBeInstanceOf(Clock::class);

        $doubles->dispose();
    }

    #[Test]
    public function classDoublesNeverRunTheDoubledDestructor(): void
    {
        DestructorProbe::$calls = 0;
        $doubles = new Doubles();
        $double = $doubles->stub(DestructorProbe::class);

        unset($double);

        Expect::that(DestructorProbe::$calls)
            ->because('class doubles suppress the doubled destructor')
            ->toBe(0);

        $doubles->dispose();
    }

    #[Test]
    public function interfacePropertiesAreDeclaredOnTheGeneratedProxy(): void
    {
        $doubles = new Doubles();
        $double = $doubles->stub(PropertyContract::class);
        $double->status = 'ready';

        Expect::that($double->status)
            ->because('a generated proxy satisfies its interface property contract')
            ->toBe('ready');

        $doubles->dispose();
    }

    #[Test]
    public function selfConstantDefaultsResolveAgainstTheDoubledType(): void
    {
        $doubles = new Doubles();
        $double = $doubles->mock(SelfConstantDefault::class, static function (MockPlan $plan): void {
            $plan->expects('mode')->andReturns('answered');
        });
        $parameter = new \ReflectionMethod($double, 'mode')->getParameters()[0];

        Expect::that($parameter->getDefaultValue())
            ->because('self constant defaults resolve against the doubled type')
            ->toBe('fast')
            ->and($double->mode())
            ->toBe('answered');

        $doubles->dispose();
    }

    #[Test]
    public function unavailableInternalDefaultsAreRejectedBeforeProxyGeneration(): void
    {
        $doubles = new Doubles();

        try {
            Expect::that(static fn(): object => $doubles->stub(\ReflectionClass::class))
                ->because('an unavailable internal default cannot produce a valid proxy signature')
                ->toThrow(
                    DoublesError::class,
                    message: 'Doubles cannot reproduce the default value of parameter $default '
                        . 'from ReflectionClass::getStaticPropertyValue() in a proxy.',
                );
        } finally {
            $doubles->dispose();
        }
    }

    #[Test]
    public function wideSignaturesRoundTripThroughTheProxy(): void
    {
        $doubles = new Doubles();
        $wide = $doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('byReference');
            $plan->expects('returnsVoid');
            $plan->expects('unionType')->with('text')->andReturns('answered');
            $plan->expects('nullable')->with('x')->andReturns(null);
            $plan->expects('variadic')->with('head', 1, 2)->andReturns(['head']);
        });

        $items = ['a'];
        $wide->byReference($items);
        $wide->returnsVoid();

        Expect::that($wide->unionType('text'))->because('wide signatures round trip through the proxy')->toBe('answered')
            ->and($wide->nullable('x'))->toBeNull()
            ->and($wide->variadic('head', 1, 2))->toBe(['head']);

        $doubles->dispose();
    }

    #[Test]
    public function aNeverReturningMethodRejectsAConfiguredReturnValue(): void
    {
        $doubles = new Doubles();
        $wide = $doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('returnsNever')->andReturns(null);
        });

        Expect::that(static fn() => $wide->returnsNever())->because('a never returning method requires andThrows()')
            ->toThrow(
                DoublesError::class,
                message: 'Greenlight\Tests\Fixture\Doubles\Wide::returnsNever() declares never. '
                    . 'Configure it with andThrows().',
            );

        $doubles->dispose();
    }

    #[Test]
    public function aStaticInterfaceMethodExplainsThatDoublesCannotInterceptIt(): void
    {
        $doubles = new Doubles();
        $double = $doubles->mock(StaticMethodFixture::class);
        $proxyClass = $double::class;

        Expect::that(static fn(): string => $proxyClass::lookup())
            ->toThrow(
                DoublesError::class,
                message: StaticMethodFixture::class . '::lookup() is static. '
                    . 'Doubles cannot intercept static methods.',
            );

        $doubles->dispose();
    }

    #[Test]
    public function aConfiguredNeverReturningMethodThrowsItsPlan(): void
    {
        $doubles = new Doubles();
        $wide = $doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('returnsNever')->andThrows(new \DomainException('halt'));
        });

        Expect::that(static fn() => $wide->returnsNever())->because('a configured never returning method throws its plan')
            ->toThrow(\DomainException::class, '/halt/');

        $doubles->dispose();
    }

    private function removeDirectory(string $directory): void
    {
        $files = \glob($directory . '/*');

        foreach ($files === false ? [] : $files as $file) {
            @\unlink($file);
        }

        @\rmdir($directory);
    }
}
