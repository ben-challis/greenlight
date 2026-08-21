<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
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
use Greenlight\Tests\Support\Subprocess;

final readonly class ProxyGenerationTest
{
    public function __construct(
        private Doubles $doubles,
        private TemporaryDirectory $tempDirectory,
    ) {}

    #[Test]
    public function theSameTypeReusesTheGeneratedClass(): void
    {
        $first = $this->doubles->spy(Calculator::class);
        $second = $this->doubles->spy(Calculator::class);

        Expect::that($second::class)->because('the same type reuses the generated class')->toBe($first::class);
    }

    #[Test]
    public function differentSignaturesGenerateDifferentClasses(): void
    {
        $alpha = $this->doubles->spy(CacheAlpha::class);
        $beta = $this->doubles->spy(CacheBeta::class);

        Expect::that($alpha::class)->because('different signatures generate different classes')->not()->toBe($beta::class);
    }

    #[Test]
    public function theDefaultCacheLivesInTheSystemTempDirKeyedByWorkingDirectory(): void
    {
        $root = \dirname(__DIR__, 3);
        $temporaryRoot = $this->tempDirectory->subdirectory('default-proxy-cache');
        $result = Subprocess::run($root, [
            \PHP_BINARY,
            '-d',
            'sys_temp_dir=' . $temporaryRoot,
            '-r',
            <<<'PHP'
            require $argv[1];

            $doubles = new Greenlight\Doubles\Doubles();
            $doubles->spy(Greenlight\Tests\Fixture\Doubles\Calculator::class);
            $doubles->dispose();
            PHP,
            $root . '/vendor/autoload.php',
        ]);
        $directory = \sprintf(
            '%s/greenlight-proxies-%s',
            $temporaryRoot,
            \substr(\sha1($root), 0, 12),
        );
        $files = \glob($directory . '/*.php');

        Expect::that($result->exitCode)
            ->because('default proxy generation MUST succeed in the private process')
            ->toBe(0);
        Expect::that($result->stderr)
            ->because('default proxy generation MUST not emit diagnostics')
            ->toBe('');
        Expect::that($files === false ? [] : $files)
            ->because('the default cache MUST use system temp and the working-directory key')
            ->toHaveCount(1);
    }

    #[Test]
    public function theProxyFileIsWrittenOnceAndReused(): void
    {
        $directory = $this->tempDirectory->subdirectory('proxy-file-reuse');
        $doubles = new Doubles($directory);

        try {
            $doubles->spy(ProxyFileProbe::class);
            $doubles->spy(ProxyFileProbe::class);

            $files = \glob($directory . '/*.php');

            Expect::that($files === false ? [] : $files)
                ->because('the proxy file is written once and reused')
                ->toHaveCount(1);
        } finally {
            $doubles->dispose();
        }
    }

    #[Test]
    public function classDoublesNeverRunTheDoubledConstructor(): void
    {
        // The Clock constructor throws. Creation of the double without an
        // exception shows that Greenlight did not run the constructor.
        $directory = $this->tempDirectory->subdirectory('constructor-suppression');
        $doubles = new Doubles($directory);

        try {
            $clock = $doubles->stub(Clock::class);

            Expect::that($clock)
                ->because('a freshly generated class double never runs the doubled constructor')
                ->toBeInstanceOf(Clock::class);
        } finally {
            $doubles->dispose();
        }
    }

    #[Test]
    public function classDoublesNeverRunTheDoubledDestructor(): void
    {
        DestructorProbe::$calls = 0;
        $double = $this->doubles->stub(DestructorProbe::class);

        unset($double);

        Expect::that(DestructorProbe::$calls)
            ->because('class doubles suppress the doubled destructor')
            ->toBe(0);
    }

    #[Test]
    public function interfacePropertiesAreDeclaredOnTheGeneratedProxy(): void
    {
        $double = $this->doubles->stub(PropertyContract::class);
        $double->status = 'ready';

        Expect::that($double->status)
            ->because('a generated proxy satisfies its interface property contract')
            ->toBe('ready');
    }

    #[Test]
    public function selfConstantDefaultsResolveAgainstTheDoubledType(): void
    {
        $double = $this->doubles->mock(SelfConstantDefault::class, static function (MockPlan $plan): void {
            $plan->expects('mode')->andReturns('answered');
        });
        $parameter = new \ReflectionMethod($double, 'mode')->getParameters()[0];

        Expect::that($parameter->getDefaultValue())
            ->because('self constant defaults resolve against the doubled type')
            ->toBe('fast');
        Expect::that($double->mode())->toBe('answered');
    }

    #[Test]
    public function unavailableInternalDefaultsAreRejectedBeforeProxyGeneration(): void
    {
        Expect::that(fn(): object => $this->doubles->stub(\ReflectionClass::class))
            ->because('an unavailable internal default cannot produce a valid proxy signature')
            ->toThrow(
                DoublesError::class,
                message: 'Doubles cannot reproduce the default value of parameter $default '
                    . 'from ReflectionClass::getStaticPropertyValue() in a proxy.',
            );
    }

    #[Test]
    public function wideSignaturesRoundTripThroughTheProxy(): void
    {
        $wide = $this->doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('byReference');
            $plan->expects('returnsVoid');
            $plan->expects('unionType')->with('text')->andReturns('answered');
            $plan->expects('nullable')->with('x')->andReturns(null);
            $plan->expects('variadic')->with('head', 1, 2)->andReturns(['head']);
        });

        $items = ['a'];
        $wide->byReference($items);
        $wide->returnsVoid();

        Expect::that($wide->unionType('text'))
            ->because('wide signatures round trip through the proxy')
            ->toBe('answered');
        Expect::that($wide->nullable('x'))->toBeNull();
        Expect::that($wide->variadic('head', 1, 2))->toBe(['head']);
    }

    #[Test]
    public function aNeverReturningMethodRejectsAConfiguredReturnValue(): void
    {
        $wide = $this->doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('returnsNever')->andReturns(null); // @phpstan-ignore greenlight.mockPlan.answer (deliberately invalid: tests runtime validation)
        });

        Expect::that(static fn() => $wide->returnsNever())->because('a never returning method requires andThrows()')
            ->toThrow(
                DoublesError::class,
                message: 'Greenlight\Tests\Fixture\Doubles\Wide::returnsNever() declares never. '
                    . 'Configure it with andThrows().',
            );
    }

    #[Test]
    public function aStaticInterfaceMethodExplainsThatDoublesCannotInterceptIt(): void
    {
        $double = $this->doubles->mock(StaticMethodFixture::class);
        $proxyClass = $double::class;

        Expect::that(static fn(): string => $proxyClass::lookup())
            ->toThrow(
                DoublesError::class,
                message: StaticMethodFixture::class . '::lookup() is static. '
                    . 'Doubles cannot intercept static methods.',
            );
    }

    #[Test]
    public function aConfiguredNeverReturningMethodThrowsItsPlan(): void
    {
        $throwable = new \DomainException('halt');
        $wide = $this->doubles->mock(Wide::class, static function (MockPlan $plan) use ($throwable): void {
            $plan->expects('returnsNever')->andThrows($throwable);
        });

        Expect::that(static fn() => $wide->returnsNever())->because('a configured never returning method throws its plan')
            ->toThrow($throwable);
    }

}
