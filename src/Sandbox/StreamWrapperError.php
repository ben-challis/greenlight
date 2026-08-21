<?php

declare(strict_types=1);

namespace Greenlight\Sandbox;

/**
 * A stream-wrapper sandbox cannot register or unregister a wrapper.
 */
final class StreamWrapperError extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function registrationFailed(
        string $scheme,
        ?string $warning,
        ?\Throwable $previous = null,
    ): self {
        return new self(\sprintf(
            'Failed to register stream wrapper "%s"%s.',
            $scheme,
            $warning === null ? '' : ': ' . $warning,
        ), $previous);
    }

    /**
     * @param non-empty-list<array{non-empty-string, string|null}> $failures
     */
    public static function unregistrationFailed(array $failures): self
    {
        $details = \array_map(
            static fn(array $failure): string => \sprintf(
                '"%s"%s',
                $failure[0],
                $failure[1] === null ? '' : ': ' . $failure[1],
            ),
            $failures,
        );

        return new self(\sprintf(
            'Failed to unregister stream wrapper%s %s.',
            \count($failures) === 1 ? '' : 's',
            \implode(', ', $details),
        ));
    }
}
