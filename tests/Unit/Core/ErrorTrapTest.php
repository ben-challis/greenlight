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
