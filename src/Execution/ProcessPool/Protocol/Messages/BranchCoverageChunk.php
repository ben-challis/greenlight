<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Protocol\Messages;

use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Test\TestId;

/**
 * Sends one bounded part of a test's covered branch identities.
 *
 * @internal
 */
final readonly class BranchCoverageChunk implements Message
{
    /** @var non-empty-string */
    public string $function;

    /**
     * @param non-empty-string $file
     * @param non-empty-string $function
     * @param non-empty-list<int<0, max>> $branches
     */
    public function __construct(
        public TestId $test,
        public string $file,
        string $function,
        public array $branches,
    ) {
        if ($function === '') {
            throw new \InvalidArgumentException('Branch coverage file and function names MUST NOT be empty.');
        }

        $this->function = $function;
    }

    #[\Override]
    public static function tag(): string
    {
        return 'branch-coverage';
    }

    #[\Override]
    public function toWire(): array
    {
        return ['test' => $this->test->toWire(), 'file' => $this->file, 'function' => $this->function, 'branches' => $this->branches];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $raw = $payload['branches'] ?? null;

        if (!\is_array($raw) || !\array_is_list($raw) || $raw === []) {
            throw InvalidWirePayload::wrongType('branches', 'a non-empty list of nonnegative branch IDs', $raw);
        }

        foreach ($raw as $branch) {
            if (!\is_int($branch) || $branch < 0) {
                throw InvalidWirePayload::wrongType('branches', 'a non-empty list of nonnegative branch IDs', $branch);
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
