<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanTemporalMatcherCaseTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function uppercaseNativeMatchersKeepTheirSignaturesAndConstraints(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->temporaryDirectory,
            <<<'PHP'
            <?php
            use Greenlight\Expect\Expect;
            Expect::calling(static fn(): float => 1.0)->returnValue()->eventually()->within(1.0)->toBeWithin(of: 1.0, delta: 0.1);
            PHP,
            <<<'PHP'
            <?php
            use Greenlight\Expect\Expect;
            Expect::calling(static fn(): float => 1.0)->returnValue()->eventually()->within(1.0)->TOBEWITHIN(of: 1.0, delta: 'close');
            Expect::calling(static fn() => (static fn(): Closure => static function (): void {})()())->eventually()->within(1.0)
                ->TOTHROW(RuntimeException::class, matching: '/x/', message: 'x');
            PHP,
        );

        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value($probe->exitCode)->toBe(1);
        Expect::value($probe->messages())->toContain('expects float, string given')
            ->toContain('toThrow() accepts either matching: or message:, not both.')
            ->not()->toContain('undefined method');
    }
}
