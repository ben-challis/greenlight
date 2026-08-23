<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\PhpStanProbe;

#[AllowParallel]
#[RequiresResource('analysis-process')]
final readonly class PhpStanMatcherSignatureTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function matcherSignaturesAreEnforced(): void
    {
        $probes = PhpStanProbe::analyzeBatch(
            $this->tempDirectory,
            [
                'synchronous matchers' => [
                    'good' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use Greenlight\Expect\Expect;

                        function greenlightGoodProbe(): void
                        {
                            Expect::that('c0ffee')->toBeHexadecimal();
                            Expect::that('c0ffee')->toHaveDigestLength(6);
                        }
                        PHP,
                    'bad' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use Greenlight\Expect\Expect;

                        function greenlightBadProbe(): void
                        {
                            Expect::that('c0ffee')->toHaveDigestLength('six');
                            Expect::that('c0ffee')->toBeHexadecimal(123);
                        }
                        PHP,
                ],
                'temporal matchers' => [
                    'good' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use Greenlight\Expect\Expect;

                        function greenlightGoodTemporalProbe(): void
                        {
                            Expect::eventually(static fn(): string => 'c0ffee')
                                ->within(1.0)
                                ->toHaveDigestLength(6);
                            Expect::consistently(static fn(): string => 'c0ffee')
                                ->for(0.1)
                                ->toBeHexadecimal();
                            Expect::eventually(static fn(): float => 1.0)
                                ->within(1.0)
                                ->toBeWithin(of: 1.0, delta: 0.1);
                            Expect::consistently(static fn(): string => 'greenlight')
                                ->for(0.1)
                                ->toBeOneOf(other: 'red', expected: 'greenlight');
                        }
                        PHP,
                    'bad' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use Greenlight\Expect\Expect;

                        function greenlightBadTemporalProbe(): void
                        {
                            Expect::eventually(static fn(): string => 'c0ffee')
                                ->within(1.0)
                                ->toHaveDigestLength('six');
                            Expect::consistently(static fn(): string => 'c0ffee')
                                ->for(0.1)
                                ->toBeHexadecimal(123);
                            Expect::eventually(static fn(): float => 1.0)
                                ->within(1.0)
                                ->toBeWithin(delta: 'close', of: 1.0);
                            Expect::consistently(static fn(): bool => true)
                                ->for(0.1)
                                ->toBeTrue(123);
                        }
                        PHP,
                ],
            ],
        );

        $probe = $probes['synchronous matchers'];
        Expect::that($probe->exitCode)->because('reflected matcher signatures are enforced')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(2);
        Expect::that($probe->messages())->toContain('toHaveDigestLength() expects int, string given')
            ->toContain('invoked with 1 parameter, 0 required');

        $probe = $probes['temporal matchers'];
        Expect::that($probe->exitCode)->because('temporal matcher signatures are enforced')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(5);
        Expect::that($probe->messages())->toContain('toHaveDigestLength() expects int, string given')
            ->toContain('toBeWithin() expects float, string given')
            ->toContain('invoked with 1 parameter, 0 required');
    }

    #[Test]
    public function matcherReturnTypesMustBeBooleanWhenDeclared(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            final class GreenlightMatcherNameCollision
            {
                public function toReturnText(): string
                {
                    return 'value';
                }
            }

            function greenlightGoodMatcherReturnProbe(): void
            {
                Expect::that('value')->toReturnBoolean();
                Expect::that('value')->toReturnMixed();
                Expect::that('value')->toReturnUntyped();
                Expect::that((new GreenlightMatcherNameCollision())->toReturnText())->toBe('value');
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadMatcherReturnProbe(): void
            {
                Expect::that('value')->toReturnText();
                Expect::eventually(static fn(): string => 'value')
                    ->within(1.0)
                    ->toReturnText();
            }
            PHP,
            FixturePath::get('PhpStanMatcherReturn/probe.neon'),
        );

        Expect::that($probe->exitCode)->because('declared matcher return types must be boolean')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(2);
        Expect::that($probe->messages())
            ->toContain('toReturnText() must return bool, but its declared return type is string');
    }
}
