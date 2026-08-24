<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Protocol\Messages;

use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Test\TestId;

/**
 * Sends one bounded part of a test's covered path identities.
 *
 * @internal
 */
final readonly class PathCoverageChunk implements Message
{
    /** @var non-empty-string */
    public string $function;

    /**
     * @param non-empty-string $file
     * @param non-empty-string $function
     * @param non-empty-list<non-empty-list<int<0, max>>> $paths
     */
    public function __construct(
        public TestId $test,
        public string $file,
        string $function,
        public array $paths,
    ) {
        if ($function === '') {
            throw new \InvalidArgumentException('Path coverage file and function names MUST NOT be empty.');
        }

        $this->function = $function;
    }

    #[\Override]
    public static function tag(): string
    {
        return 'path-coverage';
    }

    #[\Override]
    public function toWire(): array
    {
        return ['test' => $this->test->toWire(), 'file' => $this->file, 'function' => $this->function, 'paths' => $this->paths];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $raw = $payload['paths'] ?? null;

        if (!\is_array($raw) || !\array_is_list($raw) || $raw === []) {
            throw InvalidWirePayload::wrongType('paths', 'a non-empty list of branch sequences', $raw);
        }

        foreach ($raw as $path) {
            if (!\is_array($path) || !\array_is_list($path) || $path === []) {
                throw InvalidWirePayload::wrongType('paths', 'a non-empty list of branch sequences', $path);
            }

            foreach ($path as $branch) {
                if (!\is_int($branch) || $branch < 0) {
                    throw InvalidWirePayload::wrongType('paths', 'branch sequences with nonnegative IDs', $branch);
                }
            }
        }

        return new self(
            TestId::fromWire(Wire::map($payload, 'test')),
            Wire::nonEmptyString($payload, 'file'),
            Wire::nonEmptyString($payload, 'function'),
            $raw,
        );
    }
}
