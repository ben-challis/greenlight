<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * A fixture value that must be revealed explicitly.
 *
 * Object dumps and exports never include the underlying value.
 */
final readonly class SensitiveValue
{
    /**
     * Keeping the value inside a closure prevents var_export() from printing a
     * private string property. Reflection remains a trust boundary in PHP.
     *
     * @var \Closure(): string
     */
    private \Closure $revealValue;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        $this->revealValue = static fn(): string => $value;
    }

    public function reveal(): string
    {
        return ($this->revealValue)();
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }
}
