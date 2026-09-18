<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

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

            use Greenlight\Expect\Expect;

            /** @param callable(): mixed $subject */
            function greenlightGoodToThrowSubjectProbe(callable $subject): void
            {
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class);
                Expect::calling($subject)->toThrow(DomainException::class);
                Expect::calling(static fn() => throw new DomainException('boom'))->eventually()
                    ->within(1.0)
                    ->toThrow(DomainException::class);
                Expect::calling(static fn() => throw new DomainException('boom'))->consistently()
                    ->for(0.1)
                    ->toThrow(DomainException::class);
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadToThrowSubjectProbe(): void
            {
                Expect::calling(1)->toThrow(DomainException::class);
                Expect::calling('not callable')->toThrow(DomainException::class);
            }
            PHP,
        );

        Expect::value($probe->exitCode)->because('toThrow requires a callable subject')->toBe(1);
        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(4);
        Expect::value($probe->messages())->toContain('expects callable(): mixed');
    }

    #[Test]
    public function patternAndExactMessageConstraintsAreMutuallyExclusive(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightGoodToThrowProbe(): void
            {
                $failure = new DomainException('boom');

                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class);
                Expect::calling(static fn() => throw $failure)
                    ->toThrow($failure);
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, matching: '/boom/');
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, message: 'boom');
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, null, 'boom');
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(...[DomainException::class, null, 'boom']);
                Expect::calling(static fn() => throw new DomainException('boom'))->eventually()
                    ->within(1.0)
                    ->toThrow(DomainException::class, message: 'boom');
                Expect::calling(static fn() => throw new DomainException('boom'))->consistently()
                    ->for(0.1)
                    ->toThrow(DomainException::class, message: 'boom');
                Expect::calling(static fn() => throw $failure)->eventually()
                    ->within(1.0)
                    ->toThrow($failure);
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadToThrowProbe(): void
            {
                $failure = new DomainException('boom');

                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, matching: '/boom/', message: 'boom');
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, '/boom/', 'boom');
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(...[
                        'throwable' => DomainException::class,
                        'matching' => '/boom/',
                        'message' => 'boom',
                    ]);
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(...[DomainException::class, '/boom/', 'boom']);
                Expect::calling(static fn() => throw new DomainException('boom'))->eventually()
                    ->within(1.0)
                    ->toThrow(DomainException::class, matching: '/boom/', message: 'boom');
                Expect::calling(static fn() => throw new DomainException('boom'))->consistently()
                    ->for(0.1)
                    ->toThrow(DomainException::class, matching: '/boom/', message: 'boom');
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (DomainException $error): void {},
                        matching: '/boom/',
                    );
                Expect::calling(static fn() => throw new DomainException('boom'))->eventually()
                    ->within(1.0)
                    ->toThrow(
                        static function (DomainException $error): void {},
                        message: 'boom',
                    );
                Expect::calling(static fn() => throw $failure)
                    ->toThrow($failure, matching: '/boom/');
                Expect::calling(static fn() => throw $failure)->eventually()
                    ->within(1.0)
                    ->toThrow($failure, message: 'boom');
            }
            PHP,
        );

        Expect::value($probe->exitCode)->because('pattern and exact message constraints are mutually exclusive')->toBe(1);
        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(10);
        Expect::value($probe->messages())->toContain('toThrow() accepts either matching: or message:, not both');
        Expect::value($probe->messages())->toContain(
            'Do not specify matching: or message: when the throwable is a callback.',
        );
        Expect::value($probe->messages())->toContain(
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

            use Greenlight\Expect\Expect;

            function greenlightGoodToThrowCallbackProbe(): void
            {
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (DomainException $error): void {
                            Expect::value($error->getPrevious())->toBeNull();
                        },
                    );
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        throwable: static function (Throwable $error): void {},
                    );
                Expect::calling(static fn() => throw new DomainException('boom'))->eventually()
                    ->within(1.0)
                    ->toThrow(
                        static function (DomainException $error): void {},
                    );
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadToThrowCallbackProbe(): void
            {
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (): void {},
                    );
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (string $error): void {},
                    );
                Expect::calling(static fn() => throw new DomainException('boom'))->eventually()
                    ->within(1.0)
                    ->toThrow(
                        static function (DomainException &$error): void {},
                    );
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (DomainException $error, string $context): void {},
                    );
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (DomainException ...$error): void {},
                    );
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static fn(DomainException $error): int => 1,
                    );
                Expect::calling(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (?DomainException $error): void {},
                    );
            }
            PHP,
        );

        Expect::value($probe->exitCode)->toBe(1);
        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(7);
        Expect::value($probe->messages())->toContain(
            'Give the throwable callback for toThrow() one typed Throwable argument.',
        );
        Expect::value($probe->messages())->toContain(
            'Declare one named, non-null Throwable parameter type for the toThrow() callback.',
        );
        Expect::value($probe->messages())->toContain(
            'Parameter #1 $throwable of method Greenlight\\Expect\\CallExpectation<',
        );
    }
}
