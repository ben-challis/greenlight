<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Reports whether a service resolver handled, resolved, or failed one request.
 * Each state contains only the data that applies to that state.
 */
final readonly class ServiceResolution
{
    private function __construct(
        private ?object $service = null,
        private ?ServiceResolutionFailed $error = null,
    ) {}

    public static function unhandled(): self
    {
        return new self();
    }

    public static function resolved(object $service): self
    {
        return new self(service: $service);
    }

    public static function failed(ServiceResolutionFailed $error): self
    {
        return new self(error: $error);
    }

    public function isUnhandled(): bool
    {
        return $this->service === null && !$this->error instanceof ServiceResolutionFailed;
    }

    public function isResolved(): bool
    {
        return $this->service !== null;
    }

    public function isFailed(): bool
    {
        return $this->error instanceof ServiceResolutionFailed;
    }

    public function service(): object
    {
        return $this->service
            ?? throw new \LogicException('A service is not available for this resolution state.');
    }

    public function error(): ServiceResolutionFailed
    {
        return $this->error
            ?? throw new \LogicException('An error is not available for this resolution state.');
    }

    /**
     * @throws ServiceResolutionFailed if the resolver failed
     */
    public function value(): ?object
    {
        if ($this->isFailed()) {
            throw $this->error();
        }

        return $this->service;
    }
}
