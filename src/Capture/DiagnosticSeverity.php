<?php

declare(strict_types=1);

namespace Greenlight\Capture;

/**
 * Severity of a recorded PHP diagnostic. Only the non-fatal engine
 * severities are representable; anything PHP treats as fatal is never
 * recorded as a diagnostic and keeps its default handling.
 *
 * @internal
 */
enum DiagnosticSeverity: string
{
    case Notice = 'notice';
    case Warning = 'warning';
    case Deprecation = 'deprecation';

    /**
     * Maps an error level from the engine to a diagnostic severity, or null
     * when the level is not a capturable diagnostic.
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
