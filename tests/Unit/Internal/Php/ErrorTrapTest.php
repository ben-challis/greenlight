<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Php;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Php\ErrorTrap;

final class ErrorTrapTest
{
    #[Test]
    public function returnsTheOperationValueAndResetsTheWarning(): void
    {
        $warning = 'stale';

        $value = ErrorTrap::run(static fn() => 'result', $warning);

        Expect::that($value)
            ->because('the trapped operation return value MUST be preserved')
            ->toBe('result');
        Expect::that($warning)
            ->because('a trapped operation without a diagnostic MUST clear an earlier warning')
            ->toBeNull();
    }

    #[Test]
    public function capturesTheLastWarning(): void
    {
        ErrorTrap::run(static function () {
            \trigger_error('first warning', \E_USER_WARNING);
            \trigger_error('last warning', \E_USER_WARNING);
        }, $warning);

        Expect::that($warning)
            ->because('the trap MUST keep the last diagnostic message')
            ->toBe('last warning');
    }

    #[Test]
    public function doesNotForwardDiagnosticsToThePreviousHandler(): void
    {
        $handled = [];

        \set_error_handler(static function (int $severity, string $message) use (&$handled): bool {
            $handled[] = [$severity, $message];

            return true;
        });

        try {
            ErrorTrap::run(static function () {
                \trigger_error('trapped warning', \E_USER_WARNING);
            }, $warning);

            Expect::that($warning)
                ->because('the trap MUST retain the diagnostic for its caller')
                ->toBe('trapped warning');
            Expect::that($handled)
                ->because('the trap MUST NOT send the diagnostic to the host error handler')
                ->toBe([]);
        } finally {
            \restore_error_handler();
        }
    }

    #[Test]
    public function nestedTrapsKeepTheirWarningsSeparateAndRestoreTheOuterTrap(): void
    {
        ErrorTrap::run(static function () use (&$innerWarning) {
            \trigger_error('outer before inner', \E_USER_WARNING);

            ErrorTrap::run(static function () {
                \trigger_error('inner warning', \E_USER_WARNING);
            }, $innerWarning);

            \trigger_error('outer after inner', \E_USER_WARNING);
        }, $outerWarning);

        Expect::that($innerWarning)
            ->because('the inner trap MUST capture its own diagnostic')
            ->toBe('inner warning');
        Expect::that($outerWarning)
            ->because('the outer trap MUST resume after the inner trap')
            ->toBe('outer after inner');
    }

    #[Test]
    public function restoresThePreviousHandlerWhenTheOperationThrows(): void
    {
        $handled = null;
        $failure = new \RuntimeException('operation failed');

        \set_error_handler(static function (int $severity, string $message) use (&$handled): bool {
            $handled = [$severity, $message];

            return true;
        });

        try {
            Expect::that(static fn(): mixed => ErrorTrap::run(
                static fn() => throw $failure,
            ))
                ->because('the trap MUST propagate an operation error')
                ->toThrow($failure);

            \trigger_error('after trap', \E_USER_WARNING);

            Expect::that($handled)
                ->because('the trap MUST restore the previous handler after an operation error')
                ->toBe([\E_USER_WARNING, 'after trap']);
        } finally {
            \restore_error_handler();
        }
    }

    #[Test]
    public function wrapsAnOperationThrowableAfterItRestoresThePreviousHandler(): void
    {
        $handled = null;
        $failure = new \RuntimeException('operation failed');

        \set_error_handler(static function (int $severity, string $message) use (&$handled): bool {
            $handled = [$severity, $message];

            return true;
        });

        try {
            Expect::that(static fn(): mixed => ErrorTrap::run(
                operation: static fn() => throw $failure,
                wrap: static function (\Throwable $cause): \Throwable {
                    \trigger_error('wrap warning', \E_USER_WARNING);

                    return new \LogicException('wrapped operation failure', previous: $cause);
                },
            ))
                ->because('the trap MUST replace an operation error after it restores the previous handler')
                ->toThrow(
                    static function (\LogicException $error) use ($failure): void {
                        Expect::that($error->getMessage())->toBe('wrapped operation failure');
                        Expect::that($error->getPrevious())
                            ->because('the replacement error MUST preserve the operation error')
                            ->toBe($failure);
                    },
                );

            Expect::that($handled)
                ->because('the wrap callback MUST run after the trap restores the previous handler')
                ->toBe([\E_USER_WARNING, 'wrap warning']);
        } finally {
            \restore_error_handler();
        }
    }

    #[Test]
    public function preservesHandlersInstalledByTheOperation(): void
    {
        $baseline = static fn(): bool => true;
        $first = static fn(): bool => true;
        $second = static fn(): bool => true;

        \set_error_handler($baseline);

        try {
            ErrorTrap::run(static function () use ($first, $second) {
                \set_error_handler($first);
                \set_error_handler($second);
            });

            Expect::that($this->activeErrorHandler())
                ->because('the trap MUST preserve the last handler installed by the operation')
                ->toBe($second);

            \restore_error_handler();

            Expect::that($this->activeErrorHandler())
                ->because('the trap MUST preserve installed handlers in their original order')
                ->toBe($first);

            \restore_error_handler();

            Expect::that($this->activeErrorHandler())
                ->because('restoring the installed handlers MUST reveal the pre-trap handler')
                ->toBe($baseline);
        } finally {
            $this->restoreErrorHandlersThrough($baseline);
        }
    }

    #[Test]
    public function doesNotPopThePreviousHandlerWhenTheOperationRemovesTheTrap(): void
    {
        $baseline = static fn(): bool => true;

        \set_error_handler($baseline);

        try {
            ErrorTrap::run(static function () {
                \restore_error_handler();
            });

            Expect::that($this->activeErrorHandler())
                ->because('trap cleanup MUST preserve the handler stack left by the operation')
                ->toBe($baseline);
        } finally {
            $this->restoreErrorHandlersThrough($baseline);
        }
    }

    /** @return (callable(int, string, string, int): bool)|null */
    private function activeErrorHandler(): ?callable
    {
        $probe = static fn(): bool => true;
        $active = \set_error_handler($probe);
        \restore_error_handler();

        return $active;
    }

    /** @param callable(int, string, string, int): bool $last */
    private function restoreErrorHandlersThrough(callable $last): void
    {
        while (($active = $this->activeErrorHandler()) !== null) {
            \restore_error_handler();

            if ($active === $last) {
                return;
            }
        }
    }
}
