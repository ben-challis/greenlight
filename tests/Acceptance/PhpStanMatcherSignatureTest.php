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
                            Expect::value('c0ffee')->toBeHexadecimal();
                            Expect::value('c0ffee')->toHaveDigestLength(6);
                        }
                        PHP,
                    'bad' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use Greenlight\Expect\Expect;

                        function greenlightBadProbe(): void
                        {
                            Expect::value('c0ffee')->toHaveDigestLength('six');
                            Expect::value('c0ffee')->toBeHexadecimal(123);
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
                            Expect::calling(static fn(): string => 'c0ffee')->returnValue()->eventually()
                                ->within(1.0)
                                ->toHaveDigestLength(6);
                            Expect::calling(static fn(): string => 'c0ffee')->returnValue()->consistently()
                                ->for(0.1)
                                ->toBeHexadecimal();
                            Expect::calling(static fn(): float => 1.0)->returnValue()->eventually()
                                ->within(1.0)
                                ->toBeWithin(of: 1.0, delta: 0.1);
                            Expect::calling(static fn(): string => 'greenlight')->returnValue()->consistently()
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
                            Expect::calling(static fn(): string => 'c0ffee')->returnValue()->eventually()
                                ->within(1.0)
                                ->toHaveDigestLength('six');
                            Expect::calling(static fn(): string => 'c0ffee')->returnValue()->consistently()
                                ->for(0.1)
                                ->toBeHexadecimal(123);
                            Expect::calling(static fn(): float => 1.0)->returnValue()->eventually()
                                ->within(1.0)
                                ->toBeWithin(delta: 'close', of: 1.0);
                            Expect::calling(static fn(): bool => true)->returnValue()->consistently()
                                ->for(0.1)
                                ->toBeTrue(123);
                        }
                        PHP,
                ],
            ],
        );

        $probe = $probes['synchronous matchers'];
        Expect::value($probe->exitCode)->because('reflected matcher signatures are enforced')->toBe(1);
        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(2);
        Expect::value($probe->messages())->toContain('toHaveDigestLength() expects int, string given')
            ->toContain('invoked with 1 parameter, 0 required');

        $probe = $probes['temporal matchers'];
        Expect::value($probe->exitCode)->because('temporal matcher signatures are enforced')->toBe(1);
        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(5);
        Expect::value($probe->messages())->toContain('toHaveDigestLength() expects int, string given')
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
                Expect::value('value')->toReturnBoolean();
                Expect::value('value')->toReturnMixed();
                Expect::value('value')->toReturnUntyped();
                Expect::value((new GreenlightMatcherNameCollision())->toReturnText())->toBe('value');
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadMatcherReturnProbe(): void
            {
                Expect::value('value')->toReturnText();
                Expect::calling(static fn(): string => 'value')->returnValue()->eventually()
                    ->within(1.0)
                    ->toReturnText();
            }
            PHP,
            FixturePath::get('PhpStanMatcherReturn/probe.neon'),
        );

        Expect::value($probe->exitCode)->because('declared matcher return types must be boolean')->toBe(1);
        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(2);
        Expect::value($probe->messages())
            ->toContain('toReturnText() must return bool, but its declared return type is string');
    }
}
