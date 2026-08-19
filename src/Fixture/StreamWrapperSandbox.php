<?php

declare(strict_types=1);

namespace Greenlight\Fixture;

use Greenlight\Core\ErrorTrap;
use Greenlight\Harness\Disposable;

/**
 * Registers stream wrappers and unregisters them when the test scope closes.
 */
final class StreamWrapperSandbox implements Disposable
{
    /** @var list<non-empty-string> */
    private array $schemes = [];

    /**
     * @param class-string $wrapper
     *
     * @throws StreamWrapperError
     */
    public function register(string $scheme, string $wrapper): void
    {
        if ($scheme === '') {
            throw new \InvalidArgumentException('Stream wrapper scheme cannot be empty.');
        }

        if (!ErrorTrap::run(
            static fn(): bool => \stream_wrapper_register($scheme, $wrapper),
            $warning,
        )) {
            throw StreamWrapperError::registrationFailed($scheme, $warning);
        }

        $this->schemes[] = $scheme;
    }

    /** @throws StreamWrapperError */
    #[\Override]
    public function dispose(): void
    {
        $failures = [];

        foreach (\array_reverse($this->schemes) as $scheme) {
            if (!ErrorTrap::run(static fn(): bool => \stream_wrapper_unregister($scheme), $warning)) {
                $failures[] = [$scheme, $warning];
            }
        }

        $this->schemes = [];

        if ($failures !== []) {
            throw StreamWrapperError::unregistrationFailed($failures);
        }
    }
}
