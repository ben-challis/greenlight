<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Internal\Text\Utf8;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/** Contains a throwable's class, message, source location, and stack trace. */
final readonly class ThrowableDetail
{
    private const int MAX_STACK_FRAMES = 32;

    /**
     * @var non-empty-string
     */
    public string $class;

    /**
     * @var non-empty-string
     */
    public string $file;

    /**
     * @var positive-int
     */
    public int $line;

    /**
     * @param list<string> $stackFrames
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $class,
        public string $message,
        string $file,
        int $line,
        public array $stackFrames = [],
    ) {
        if ($class === '') {
            throw new \InvalidArgumentException('Throwable detail class must not be empty.');
        }

        if ($file === '') {
            throw new \InvalidArgumentException('Throwable detail file must not be empty.');
        }

        if ($line < 1) {
            throw new \InvalidArgumentException('Throwable detail line must be at least 1.');
        }

        $this->class = $class;
        $this->file = $file;
        $this->line = $line;
    }

    /**
     * Records at most 32 stack frames. Adds a truncation marker if more frames exist.
     */
    public static function fromThrowable(\Throwable $throwable): self
    {
        $frames = [];

        foreach ($throwable->getTrace() as $index => $frame) {
            if ($index >= self::MAX_STACK_FRAMES) {
                $frames[] = '... (trace truncated)';

                break;
            }

            $function = $frame['function'];
            $class = $frame['class'] ?? null;
            $call = $class === null ? $function : $class . ($frame['type'] ?? '::') . $function;

            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;
            $where = \is_string($file) ? $file . ':' . ($line ?? 0) : '[internal]';

            $frames[] = Utf8::scrub($call . ' at ' . $where);
        }

        $file = Utf8::scrub($throwable->getFile());
        $line = $throwable->getLine();

        return new self(
            $throwable::class,
            Utf8::scrub($throwable->getMessage()),
            $file !== '' ? $file : '[unknown]',
            \max(1, $line),
            $frames,
        );
    }

    /**
     * @internal
     *
     * The frame limit prevents a deep trace from making the wire payload
     * too large.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'class' => $this->class,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'stackFrames' => $this->stackFrames,
        ];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'class'),
            Wire::string($payload, 'message'),
            Wire::nonEmptyString($payload, 'file'),
            \max(1, Wire::int($payload, 'line')),
            Wire::listOfStrings($payload, 'stackFrames'),
        );
    }
}
