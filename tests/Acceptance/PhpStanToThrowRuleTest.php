<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

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

            function greenlightGoodToThrowSubjectProbe(mixed $subject): void
            {
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class);
                Expect::that($subject)->toThrow(DomainException::class);
                Expect::eventually(static fn(): Closure => static fn() => throw new DomainException('boom'))
                    ->within(1.0)
                    ->toThrow(DomainException::class);
                Expect::consistently(static fn(): Closure => static fn() => throw new DomainException('boom'))
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
                Expect::that(1)->toThrow(DomainException::class);
                Expect::that('not callable')->toThrow(DomainException::class);
                Expect::eventually(static fn(): int => 1)
                    ->within(1.0)
                    ->toThrow(DomainException::class);
                Expect::consistently(static fn(): string => 'not callable')
                    ->for(0.1)
                    ->toThrow(DomainException::class);
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('toThrow requires a callable subject')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(4);
        Expect::that($probe->messages())->toContain('toThrow() requires a callable subject. The subject type is');
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

                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class);
                Expect::that(static fn() => throw $failure)
                    ->toThrow($failure);
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, matching: '/boom/');
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, message: 'boom');
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, null, 'boom');
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(...[DomainException::class, null, 'boom']);
                Expect::eventually(static fn(): Closure => static fn() => throw new DomainException('boom'))
                    ->within(1.0)
                    ->toThrow(DomainException::class, message: 'boom');
                Expect::consistently(static fn(): Closure => static fn() => throw new DomainException('boom'))
                    ->for(0.1)
                    ->toThrow(DomainException::class, message: 'boom');
                Expect::eventually(static fn(): Closure => static fn() => throw $failure)
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

                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, matching: '/boom/', message: 'boom');
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, '/boom/', 'boom');
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(...[
                        'throwable' => DomainException::class,
                        'matching' => '/boom/',
                        'message' => 'boom',
                    ]);
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(...[DomainException::class, '/boom/', 'boom']);
                Expect::eventually(static fn(): Closure => static fn() => throw new DomainException('boom'))
                    ->within(1.0)
                    ->toThrow(DomainException::class, matching: '/boom/', message: 'boom');
                Expect::consistently(static fn(): Closure => static fn() => throw new DomainException('boom'))
                    ->for(0.1)
                    ->toThrow(DomainException::class, matching: '/boom/', message: 'boom');
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (DomainException $error): void {},
                        matching: '/boom/',
                    );
                Expect::eventually(static fn(): Closure => static fn() => throw new DomainException('boom'))
                    ->within(1.0)
                    ->toThrow(
                        static function (DomainException $error): void {},
                        message: 'boom',
                    );
                Expect::that(static fn() => throw $failure)
                    ->toThrow($failure, matching: '/boom/');
                Expect::eventually(static fn(): Closure => static fn() => throw $failure)
                    ->within(1.0)
                    ->toThrow($failure, message: 'boom');
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('pattern and exact message constraints are mutually exclusive')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(10);
        Expect::that($probe->messages())->toContain('toThrow() accepts either matching: or message:, not both');
        Expect::that($probe->messages())->toContain(
            'Do not specify matching: or message: when the throwable is a callback.',
        );
        Expect::that($probe->messages())->toContain(
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
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (DomainException $error): void {
                            Expect::that($error->getPrevious())->toBeNull();
                        },
                    );
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        throwable: static function (Throwable $error): void {},
                    );
                Expect::eventually(static fn(): Closure => static fn() => throw new DomainException('boom'))
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
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (): void {},
                    );
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (string $error): void {},
                    );
                Expect::eventually(static fn(): Closure => static fn() => throw new DomainException('boom'))
                    ->within(1.0)
                    ->toThrow(
                        static function (DomainException &$error): void {},
                    );
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (DomainException $error, string $context): void {},
                    );
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (DomainException ...$error): void {},
                    );
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static fn(DomainException $error): int => 1,
                    );
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(
                        static function (?DomainException $error): void {},
                    );
            }
            PHP,
        );

        Expect::that($probe->exitCode)->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(7);
        Expect::that($probe->messages())->toContain(
            'The throwable callback for toThrow() MUST accept one typed Throwable argument.',
        );
        Expect::that($probe->messages())->toContain(
            'The throwable callback for toThrow() MUST declare one named, non-null Throwable parameter type.',
        );
        Expect::that($probe->messages())->toContain(
            'Parameter #1 $throwable of method Greenlight\\Expect\\Expectation<Closure>::toThrow() expects',
        );
    }
}
