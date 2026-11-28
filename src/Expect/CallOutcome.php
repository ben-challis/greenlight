<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Captures one invocation, including a null return or a thrown error.
 *
 * An outcome is callable. A call matcher can therefore use it as its subject.
 * Each call replays the captured return value or throwable.
 *
 * @internal
 *
 * @template-covariant T
 */
final readonly class CallOutcome
{
    /**
     * @param \Closure(): T $replay
     * @param mixed $value The return value, for diagnostics only
     */
    private function __construct(
        private \Closure $replay,
        private mixed $value,
        private ?\Throwable $throwable,
    ) {}

    /**
     * @template TResult
     *
     * @param \Closure(): TResult $call
     *
     * @return self<TResult>
     */
    public static function capture(\Closure $call): self
    {
        try {
            $value = $call();
        } catch (\Throwable $throwable) {
            return new self(static fn() => throw $throwable, null, $throwable);
        }

        return new self(static fn() => $value, $value, null);
    }

    /** @return T */
    public function replay(): mixed
    {
        return ($this->replay)();
    }

    /** @return T */
    public function __invoke(): mixed
    {
        return $this->replay();
    }

    /**
     * Describes the outcome for an observation log. A closure that replays
     * the outcome has no useful rendered form.
     */
    public function render(ValueRenderer $renderer): string
    {
        if ($this->throwable instanceof \Throwable) {
            return \sprintf(
                'threw %s with message %s',
                $this->throwable::class,
                $renderer->render($this->throwable->getMessage()),
            );
        }

        return 'returned ' . $renderer->render($this->value);
    }
}
