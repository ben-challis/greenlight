<?php

declare(strict_types=1);

namespace Greenlight\Core\Result;

use Greenlight\Core\Wire\Utf8;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Rendered description of an unexpected throwable.
 *
 * Stack frames are bounded so a deep trace cannot bloat the wire.
 */
final readonly class ThrowableDetail implements WireSerializable
{
    private const int MAX_STACK_FRAMES = 32;

    /**
     * @param non-empty-string $class
     * @param non-empty-string $file
     * @param positive-int $line
     * @param list<string> $stackFrames
     */
    public function __construct(
        public string $class,
        public string $message,
        public string $file,
        public int $line,
        public array $stackFrames = [],
    ) {}

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

    #[\Override]
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

    #[\Override]
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
