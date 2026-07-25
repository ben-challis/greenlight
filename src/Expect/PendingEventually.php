<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Configures polling before eventually applying one matcher.
 *
 * @template T
 */
final class PendingEventually
{
    private const float DEFAULT_INTERVAL_SECONDS = 0.025;

    private float $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS;

    /**
     * @var list<class-string<\Exception>>
     */
    private array $retryOnExceptions = [];

    /**
     * @param \Closure(): T $probe
     * @param list<ExpectationExtension> $extensions
     */
    public function __construct(
        private readonly \Closure $probe,
        private readonly PollingClock $clock,
        private readonly ?float $attemptDeadline,
        private readonly ValueRenderer $renderer,
        private readonly array $extensions,
    ) {}

    /**
     * @return self<T>
     */
    public function pollEvery(float $seconds): self
    {
        $this->requireDuration($seconds, 'Polling interval', minimum: 0.001);
        $this->intervalSeconds = $seconds;

        return $this;
    }

    /**
     * @param class-string<\Exception> ...$types
     *
     * @return self<T>
     */
    public function retryOnException(string ...$types): self
    {
        $validated = \array_map($this->requireExceptionType(...), $types);

        $this->retryOnExceptions = \array_values(\array_unique([
            ...$this->retryOnExceptions,
            ...$validated,
        ]));

        return $this;
    }

    /**
     * @return EventuallyExpectation<T>
     */
    public function within(float $seconds): EventuallyExpectation
    {
        $this->requireDuration($seconds, 'Eventually duration');

        return new EventuallyExpectation(
            $this->probe,
            $this->clock,
            $this->attemptDeadline,
            $this->intervalSeconds,
            $seconds,
            $this->retryOnExceptions,
            $this->renderer,
            $this->extensions,
        );
    }

    private function requireDuration(float $seconds, string $label, float $minimum = 0.0): void
    {
        if (!\is_finite($seconds) || $seconds <= 0.0 || $seconds < $minimum) {
            throw new \InvalidArgumentException(\sprintf(
                '%s seconds must be finite and at least %.3f.',
                $label,
                $minimum,
            ));
        }
    }

    /**
     * @return class-string<\Exception>
     */
    private function requireExceptionType(string $type): string
    {
        if (!\is_a($type, \Exception::class, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Retry exception type "%s" must extend Exception.',
                $type,
            ));
        }

        return $type;
    }
}
