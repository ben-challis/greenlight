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
use Greenlight\Tests\Fixture\Doubles\ProxyFileProbe;
use Greenlight\Tests\Fixture\Doubles\Wide;

final class ProxyGenerationTest
{
    #[Test]
    public function theSameTypeReusesTheGeneratedClass(): void
    {
        $doubles = new Doubles();
        $first = $doubles->spy(Calculator::class);
        $second = $doubles->spy(Calculator::class);

        new Expect()->that($second::class)->toBe($first::class);

        $doubles->dispose();
    }

    #[Test]
    public function differentSignaturesGenerateDifferentClasses(): void
    {
        $doubles = new Doubles();
        $alpha = $doubles->spy(CacheAlpha::class);
        $beta = $doubles->spy(CacheBeta::class);

        new Expect()->that($alpha::class)->not()->toBe($beta::class);

        $doubles->dispose();
    }

    #[Test]
    public function theProxyFileIsWrittenOnceAndReused(): void
    {
        $directory = \sys_get_temp_dir() . '/greenlight-doubles-' . \bin2hex(\random_bytes(6));
        $doubles = new Doubles($directory);

        $doubles->spy(ProxyFileProbe::class);
        $doubles->spy(ProxyFileProbe::class);

        $files = \glob($directory . '/*.php');

        new Expect()->that($files === false ? [] : $files)->toHaveCount(1);

        $doubles->dispose();
        $this->removeDirectory($directory);
    }

    #[Test]
    public function classDoublesNeverRunTheDoubledConstructor(): void
    {
        // The Clock constructor throws; creating the double without an
        // exception proves it never ran.
        $doubles = new Doubles();
        $clock = $doubles->stub(Clock::class);

        new Expect()->that($clock)->toBeInstanceOf(Clock::class);

        $doubles->dispose();
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

        new Expect()->that($wide->unionType('text'))->toBe('answered')
            ->and($wide->nullable('x'))->toBeNull()
            ->and($wide->variadic('head', 1, 2))->toBe(['head']);

        $doubles->dispose();
    }

    #[Test]
    public function anUnconfiguredNeverReturningMethodIsAnAuthoringError(): void
    {
        $doubles = new Doubles();
        $wide = $doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('returnsNever');
        });

        new Expect()->that(static fn() => $wide->returnsNever())
            ->toThrow(DoublesError::class, '/no configured answer/');

        $doubles->dispose();
    }

    #[Test]
    public function aConfiguredNeverReturningMethodThrowsItsPlan(): void
    {
        $doubles = new Doubles();
        $wide = $doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('returnsNever')->andThrows(new \DomainException('halt'));
        });

        new Expect()->that(static fn() => $wide->returnsNever())
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
