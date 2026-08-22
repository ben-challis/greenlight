<?php

declare(strict_types=1);

namespace Greenlight\Sandbox;

use Greenlight\Harness\Disposable;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Registers stream wrappers and unregisters them when the test scope closes.
 */
final class StreamWrappers implements Disposable
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

        $registered = ErrorTrap::run(
            static fn() => \stream_wrapper_register($scheme, $wrapper),
            $warning,
            wrap: static fn(\Throwable $error): StreamWrapperError =>
                StreamWrapperError::registrationFailed($scheme, $error->getMessage(), $error),
        );

        if (!$registered) {
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
            $unregistered = ErrorTrap::run(static fn() => \stream_wrapper_unregister($scheme), $warning);

            if (!$unregistered) {
                $failures[] = [$scheme, $warning];
            }
        }

        $this->schemes = [];

        if ($failures !== []) {
            throw StreamWrapperError::unregistrationFailed($failures);
        }
    }
}
