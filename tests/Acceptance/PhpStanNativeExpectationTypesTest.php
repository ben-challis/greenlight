<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\PhpStanProbe;

use function Greenlight\expect;

#[AllowParallel]
#[RequiresResource('analysis-process')]
final readonly class PhpStanNativeExpectationTypesTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function nativeDeclarationsSeparateValuesAndCallsWithoutExtensions(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP_WRAP'
            <?php
            use Greenlight\Expect\Expect;
            use function PHPStan\Testing\assertType;

            function valid(): void {
                Expect::value(1)->not()->toBe(2);
                Expect::calling(static fn() => throw new RuntimeException())->toThrow();
                Expect::calling(static fn(): int => 1)->toReturn(1);
                $value = Expect::calling(static fn(): int => 1)->returnValue();
                assertType('Greenlight\Expect\ReturnValueExpectation<int>', $value);
                assertType('Greenlight\Expect\Expectation<int>', $value->toBeInt());
                assertType('Greenlight\Expect\Expectation<int>', $value->eventually()->within(1.0)->toBe(1));
                $value->consistently()->for(0.1)->toBe(1);
                Expect::calling(static fn() => throw new RuntimeException())->eventually()->within(1.0)->toThrow();
            }
            PHP_WRAP,
            <<<'PHP'
            <?php
            use Greenlight\Expect\Expect;

            function invalid(): void {
                Expect::value(static fn() => 1)->toThrow();
                Expect::calling(static fn() => 1)->toBe(1);
                Expect::calling(static fn() => 1)->returnValue()->toThrow();
                Expect::calling(static fn() => 1)->returnValue()->eventually()->within(1.0)->toThrow();
                Expect::calling(static fn() => 1)->eventually()->toThrow();
                Expect::calling(42);
            }
            PHP,
            FixturePath::get('PhpStanNativeExpectations/probe.neon'),
        );

        expect($probe->goodErrors)->toBe([]);
        expect($probe->exitCode)->toBe(1);
        expect($probe->errors)->toHaveCount(7);
        expect($probe->messages())->toContain('undefined method');
        expect($probe->messages())->toContain('expects callable():');
    }
}
