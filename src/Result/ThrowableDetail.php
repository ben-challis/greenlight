<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Internal\Text\Utf8;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Contains a throwable's class, message, source location, and stack trace.
 *
 * The message has a limit of 64 KiB. The file and each stack frame have a
 * limit of 4 KiB. Longer text keeps its first part and a truncation marker.
 */
final readonly class ThrowableDetail
{
    private const int MAX_STACK_FRAMES = 32;
    private const int MAX_MESSAGE_BYTES = 65_536;
    private const int MAX_LOCATION_BYTES = 4_096;

    public string $message;

    /**
     * @var list<string>
     */
    public array $stackFrames;

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
        string $message,
        string $file,
        int $line,
        array $stackFrames = [],
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
        $this->message = BoundedText::head($message, self::MAX_MESSAGE_BYTES);
        $boundedFile = BoundedText::head($file, self::MAX_LOCATION_BYTES);
        $this->file = $boundedFile === '' ? $file : $boundedFile;
        $this->line = $line;
        $this->stackFrames = \array_map(
            static fn(string $frame): string => BoundedText::head($frame, self::MAX_LOCATION_BYTES),
            $stackFrames,
        );
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
