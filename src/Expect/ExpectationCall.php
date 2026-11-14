<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Result\FailureDetail;

/**
 * Represents one matcher call that a temporal expectation repeats.
 * It preserves argument names and makes one-shot matcher input reusable.
 *
 * @internal
 */
final readonly class ExpectationCall
{
    /**
     * @param array<array-key, mixed> $arguments
     */
    private function __construct(
        private string $name,
        private array $arguments,
    ) {}

    /** @param array<array-key, mixed> $arguments */
    public static function forImmediate(string $name, array $arguments): self
    {
        return new self($name, $arguments);
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @throws ExpectationFailed
     */
    public static function forTemporal(string $name, array $arguments): self
    {
        $nativeName = \strtolower($name);

        if ($nativeName === 'tobein') {
            self::makeIterableReusable($arguments, 'haystack', 0);
        }

        if ($nativeName === 'tothrow') {
            $throwable = self::argument($arguments, 'throwable', 0);
            $matching = self::argument($arguments, 'matching', 1);
            $message = self::argument($arguments, 'message', 2);
            $error = ThrowableConstraint::validationError($throwable, $matching, $message);

            if ($error !== null) {
                throw ExpectationFailed::fromDetail(new FailureDetail(
                    $error,
                    location: CallSite::capture(),
                ));
            }
        }

        return new self($name, $arguments);
    }

    /**
     * @return MatcherEvaluation<T>
     *
     * @template T
     *
     * @param MatcherEvaluation<T> $expectation
     *
     * @throws \BadMethodCallException if no native or registered extension matcher has the requested name
     */
    public function invoke(MatcherEvaluation $expectation): MatcherEvaluation
    {
        $matcher = [$expectation, $this->name];

        if (!\is_callable($matcher)) {
            throw new \LogicException(\sprintf(
                'Matcher "%s" is not callable.',
                $this->name,
            ));
        }

        $result = \call_user_func_array($matcher, $this->arguments);

        if (!$result instanceof MatcherEvaluation) {
            throw new \LogicException(\sprintf(
                'Matcher "%s" did not return an MatcherEvaluation.',
                $this->name,
            ));
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    private static function makeIterableReusable(
        array &$arguments,
        string $name,
        int $position,
    ): void {
        $key = \array_key_exists($name, $arguments)
            ? $name
            : (\array_key_exists($position, $arguments) ? $position : null);

        if ($key !== null && $arguments[$key] instanceof \Traversable) {
            $arguments[$key] = \iterator_to_array($arguments[$key], false);
        }
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    private static function argument(array $arguments, string $name, int $position): mixed
    {
        return $arguments[$name] ?? $arguments[$position] ?? null;
    }
}
