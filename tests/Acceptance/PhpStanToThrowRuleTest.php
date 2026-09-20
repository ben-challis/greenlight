<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

use function Greenlight\expect;

#[AllowParallel]
#[RequiresResource('analysis-process')]
final readonly class PhpStanToThrowRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function subjectMustBeCallable(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use function Greenlight\expect;

            /** @param callable(): mixed $subject */
            function greenlightGoodToThrowSubjectProbe(callable $subject): void
            {
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class);
                expect()->calling($subject)->toThrow(DomainException::class);
                expect()->calling(static fn() => throw new DomainException('boom'))->eventually()
                    ->within(1.0)
                    ->toThrow(DomainException::class);
                expect()->calling(static fn() => throw new DomainException('boom'))->consistently()
                    ->for(0.1)
                    ->toThrow(DomainException::class);
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use function Greenlight\expect;

            function greenlightBadToThrowSubjectProbe(): void
            {
                expect()->calling(1)->toThrow(DomainException::class);
                expect()->calling('not callable')->toThrow(DomainException::class);
            }
            PHP,
        );

        expect($probe->exitCode)->because('toThrow requires a callable subject')->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(4);
        expect($probe->messages())->toContain('expects callable(): mixed');
    }

    #[Test]
    public function patternAndExactMessageConstraintsAreMutuallyExclusive(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use function Greenlight\expect;

            function greenlightGoodToThrowProbe(): void
            {
                $failure = new DomainException('boom');

                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class);
                expect()->calling(static fn() => throw $failure)
                    ->toThrow($failure);
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, matching: '/boom/');
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, message: 'boom');
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, null, 'boom');
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(...[DomainException::class, null, 'boom']);
                expect()->calling(static fn() => throw new DomainException('boom'))->eventually()
                    ->within(1.0)
                    ->toThrow(DomainException::class, message: 'boom');
                expect()->calling(static fn() => throw new DomainException('boom'))->consistently()
                    ->for(0.1)
                    ->toThrow(DomainException::class, message: 'boom');
                expect()->calling(static fn() => throw $failure)->eventually()
                    ->within(1.0)
                    ->toThrow($failure);
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use function Greenlight\expect;

            function greenlightBadToThrowProbe(): void
            {
                $failure = new DomainException('boom');

                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, matching: '/boom/', message: 'boom');
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, '/boom/', 'boom');
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(...[
                        'throwable' => DomainException::class,
                        'matching' => '/boom/',
                        'message' => 'boom',
                    ]);
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(...[DomainException::class, '/boom/', 'boom']);
                expect()->calling(static fn() => throw new DomainException('boom'))->eventually()
                    ->within(1.0)
                    ->toThrow(DomainException::class, matching: '/boom/', message: 'boom');
                expect()->calling(static fn() => throw new DomainException('boom'))->consistently()
                    ->for(0.1)
                    ->toThrow(DomainException::class, matching: '/boom/', message: 'boom');
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (DomainException $error): void {},
                        matching: '/boom/',
                    );
                expect()->calling(static fn() => throw new DomainException('boom'))->eventually()
                    ->within(1.0)
                    ->toThrow(
                        static function (DomainException $error): void {},
                        message: 'boom',
                    );
                expect()->calling(static fn() => throw $failure)
                    ->toThrow($failure, matching: '/boom/');
                expect()->calling(static fn() => throw $failure)->eventually()
                    ->within(1.0)
                    ->toThrow($failure, message: 'boom');
            }
            PHP,
        );

        expect($probe->exitCode)->because('pattern and exact message constraints are mutually exclusive')->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(10);
        expect($probe->messages())
            ->toContain('toThrow() accepts either matching: or message:, not both')
            ->toContain(
                'Do not specify matching: or message: when the throwable is a callback.',
            )
            ->toContain(
                'Do not specify matching: or message: when the throwable argument is a Throwable instance.',
            );
    }

    #[Test]
    public function throwableCallbackDeclaresTheExpectedThrowableType(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use function Greenlight\expect;

            function greenlightGoodToThrowCallbackProbe(): void
            {
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (DomainException $error): void {
                            expect($error->getPrevious())->toBeNull();
                        },
                    );
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        throwable: static function (Throwable $error): void {},
                    );
                expect()->calling(static fn() => throw new DomainException('boom'))->eventually()
                    ->within(1.0)
                    ->toThrow(
                        static function (DomainException $error): void {},
                    );
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use function Greenlight\expect;

            function greenlightBadToThrowCallbackProbe(): void
            {
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (): void {},
                    );
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (string $error): void {},
                    );
                expect()->calling(static fn() => throw new DomainException('boom'))->eventually()
                    ->within(1.0)
                    ->toThrow(
                        static function (DomainException &$error): void {},
                    );
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (DomainException $error, string $context): void {},
                    );
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (DomainException ...$error): void {},
                    );
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static fn(DomainException $error): int => 1,
                    );
                expect()->calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (?DomainException $error): void {},
                    );
            }
            PHP,
        );

        expect($probe->exitCode)->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(7);
        expect($probe->messages())
            ->toContain(
                'Give the throwable callback for toThrow() one typed Throwable argument.',
            )
            ->toContain(
                'Declare one named, non-null Throwable parameter type for the toThrow() callback.',
            )
            ->toContain(
                'Parameter #1 $throwable of method Greenlight\\Expect\\CallExpectation<',
            );
    }
}
