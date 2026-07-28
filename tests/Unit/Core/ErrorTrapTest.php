<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\ErrorTrap;
use Greenlight\Expect\Expect;

final class ErrorTrapTest
{
    #[Test]
    public function returnsTheOperationValueAndResetsTheWarning(): void
    {
        $warning = 'stale';

        $value = ErrorTrap::run(static fn(): string => 'result', $warning);

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
        ErrorTrap::run(static function (): void {
            \trigger_error('first warning', \E_USER_WARNING);
            \trigger_error('last warning', \E_USER_WARNING);
        }, $warning);

        Expect::that($warning)
            ->because('the trap MUST keep the last diagnostic message')
            ->toBe('last warning');
    }

    #[Test]
    public function nestedTrapsKeepTheirWarningsSeparateAndRestoreTheOuterTrap(): void
    {
        ErrorTrap::run(static function () use (&$innerWarning): void {
            \trigger_error('outer before inner', \E_USER_WARNING);

            ErrorTrap::run(static function (): void {
                \trigger_error('inner warning', \E_USER_WARNING);
            }, $innerWarning);

            \trigger_error('outer after inner', \E_USER_WARNING);
        }, $outerWarning);

        Expect::that($innerWarning)
            ->because('the inner trap MUST capture its own diagnostic')
            ->toBe('inner warning')
            ->and($outerWarning)
            ->because('the outer trap MUST resume after the inner trap')
            ->toBe('outer after inner');
    }

    #[Test]
    public function preservesAHandlerInstalledByTheOperationWithoutLeavingItsTrapActive(): void
    {
        $previousMessages = [];
        $operationMessages = [];

        \set_error_handler(static function (int $severity, string $message) use (&$previousMessages): bool {
            $previousMessages[] = [$severity, $message];

            return true;
        });

        try {
            ErrorTrap::run(static function () use (&$operationMessages): void {
                \set_error_handler(
                    static function (int $severity, string $message) use (&$operationMessages): bool {
                        $operationMessages[] = [$severity, $message];

                        return true;
                    },
                );
            }, $warning);

            \trigger_error('operation handler', \E_USER_WARNING);
            \restore_error_handler();
            \trigger_error('previous handler', \E_USER_WARNING);

            Expect::that($operationMessages)
                ->because('the operation handler MUST remain active after the trap')
                ->toBe([[\E_USER_WARNING, 'operation handler']])
                ->and($previousMessages)
                ->because('one restore MUST return to the handler that preceded the trap')
                ->toBe([[\E_USER_WARNING, 'previous handler']])
                ->and($warning)
                ->because('the removed trap MUST NOT capture later diagnostics')
                ->toBeNull();
        } finally {
            \restore_error_handler();
        }
    }

    #[Test]
    public function restoresThePreviousHandlerWhenTheOperationThrows(): void
    {
        $handled = null;

        \set_error_handler(static function (int $severity, string $message) use (&$handled): bool {
            $handled = [$severity, $message];

            return true;
        });

        try {
            Expect::that(static fn(): mixed => ErrorTrap::run(
                static fn(): never => throw new \RuntimeException('operation failed'),
            ))
                ->because('the trap MUST propagate an operation error')
                ->toThrow(\RuntimeException::class, message: 'operation failed');

            \trigger_error('after trap', \E_USER_WARNING);

            Expect::that($handled)
                ->because('the trap MUST restore the previous handler after an operation error')
                ->toBe([\E_USER_WARNING, 'after trap']);
        } finally {
            \restore_error_handler();
        }
    }
}
