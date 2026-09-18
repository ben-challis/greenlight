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

                        use function Greenlight\expect;

                        function greenlightGoodProbe(): void
                        {
                            expect('c0ffee')->toBeHexadecimal();
                            expect('c0ffee')->toHaveDigestLength(6);
                        }
                        PHP,
                    'bad' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use function Greenlight\expect;

                        function greenlightBadProbe(): void
                        {
                            expect('c0ffee')->toHaveDigestLength('six');
                            expect('c0ffee')->toBeHexadecimal(123);
                        }
                        PHP,
                ],
                'temporal matchers' => [
                    'good' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use function Greenlight\expect;

                        function greenlightGoodTemporalProbe(): void
                        {
                            expect()->calling(static fn(): string => 'c0ffee')->returnValue()->eventually()
                                ->within(1.0)
                                ->toHaveDigestLength(6);
                            expect()->calling(static fn(): string => 'c0ffee')->returnValue()->consistently()
                                ->for(0.1)
                                ->toBeHexadecimal();
                            expect()->calling(static fn(): float => 1.0)->returnValue()->eventually()
                                ->within(1.0)
                                ->toBeWithin(of: 1.0, delta: 0.1);
                            expect()->calling(static fn(): string => 'greenlight')->returnValue()->consistently()
                                ->for(0.1)
                                ->toBeOneOf(other: 'red', expected: 'greenlight');
                        }
                        PHP,
                    'bad' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use function Greenlight\expect;

                        function greenlightBadTemporalProbe(): void
                        {
                            expect()->calling(static fn(): string => 'c0ffee')->returnValue()->eventually()
                                ->within(1.0)
                                ->toHaveDigestLength('six');
                            expect()->calling(static fn(): string => 'c0ffee')->returnValue()->consistently()
                                ->for(0.1)
                                ->toBeHexadecimal(123);
                            expect()->calling(static fn(): float => 1.0)->returnValue()->eventually()
                                ->within(1.0)
                                ->toBeWithin(delta: 'close', of: 1.0);
                            expect()->calling(static fn(): bool => true)->returnValue()->consistently()
                                ->for(0.1)
                                ->toBeTrue(123);
                        }
                        PHP,
                ],
            ],
        );

        $probe = $probes['synchronous matchers'];
        expect($probe->exitCode)->because('reflected matcher signatures are enforced')->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(2);
        expect($probe->messages())->toContain('toHaveDigestLength() expects int, string given')
            ->toContain('invoked with 1 parameter, 0 required');

        $probe = $probes['temporal matchers'];
        expect($probe->exitCode)->because('temporal matcher signatures are enforced')->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(5);
        expect($probe->messages())->toContain('toHaveDigestLength() expects int, string given')
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

            use function Greenlight\expect;

            final class GreenlightMatcherNameCollision
            {
                public function toReturnText(): string
                {
                    return 'value';
                }
            }

            function greenlightGoodMatcherReturnProbe(): void
            {
                expect('value')->toReturnBoolean();
                expect('value')->toReturnMixed();
                expect('value')->toReturnUntyped();
                expect((new GreenlightMatcherNameCollision())->toReturnText())->toBe('value');
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use function Greenlight\expect;

            function greenlightBadMatcherReturnProbe(): void
            {
                expect('value')->toReturnText();
                expect()->calling(static fn(): string => 'value')->returnValue()->eventually()
                    ->within(1.0)
                    ->toReturnText();
            }
            PHP,
            FixturePath::get('PhpStanMatcherReturn/probe.neon'),
        );

        expect($probe->exitCode)->because('declared matcher return types must be boolean')->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(2);
        expect($probe->messages())
            ->toContain('toReturnText() must return bool, but its declared return type is string');
    }
}
