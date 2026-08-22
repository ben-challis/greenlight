<?php

declare(strict_types=1);

namespace Greenlight\Result;

/**
 * Greenlight captures only notices, warnings, and deprecations. PHP uses its
 * default behavior for all other error levels.
 */
enum DiagnosticSeverity: string
{
    case Notice = 'notice';
    case Warning = 'warning';
    case Deprecation = 'deprecation';

    /**
     * Maps an engine error level to a diagnostic severity.
     *
     * Returns null if Greenlight cannot capture the diagnostic.
     */
    public static function fromErrorLevel(int $level): ?self
    {
        return match ($level) {
            \E_NOTICE, \E_USER_NOTICE => self::Notice,
            \E_WARNING, \E_USER_WARNING => self::Warning,
            \E_DEPRECATED, \E_USER_DEPRECATED => self::Deprecation,
            default => null,
        };
    }
}
