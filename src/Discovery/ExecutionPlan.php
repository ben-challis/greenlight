<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * The ordered, fully expanded list of tests a run will execute.
 *
 * Entries are grouped by class: all entries for a class are contiguous,
 * classes appear in plan order, and methods keep their declaration order
 * within the class.
 *
 * Given the same directories, filters, seed, and code state the plan is
 * byte-identical across processes and runs.
 *
 * @internal
 */
final readonly class ExecutionPlan implements WireSerializable, \Countable
{
    /**
     * @var list<PlanEntry>
     */
    public array $entries;

    /**
     * @param list<PlanEntry> $entries
     *
     * @throws \InvalidArgumentException when entries are not grouped by class
     */
    public function __construct(array $entries, public ?int $seed = null)
    {
        $seen = [];
        $current = null;

        foreach ($entries as $entry) {
            $class = $entry->id->class;

            if ($class === $current) {
                continue;
            }

            if (isset($seen[$class])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Execution plan entries must be grouped by class; "%s" appears in more than one block.',
                    $class,
                ));
            }

            $seen[$class] = true;
            $current = $class;
        }

        $this->entries = $entries;
    }

    #[\Override]
    public function count(): int
    {
        return \count($this->entries);
    }

    /**
     * Class names in plan order.
     *
     * @return list<non-empty-string>
     */
    public function classes(): array
    {
        $classes = [];

        foreach ($this->entries as $entry) {
            $class = $entry->id->class;

            if (($classes[\count($classes) - 1] ?? null) !== $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return array<non-empty-string, non-empty-list<PlanEntry>>
     */
    public function entriesByClass(): array
    {
        $byClass = [];

        foreach ($this->entries as $entry) {
            $byClass[$entry->id->class][] = $entry;
        }

        return $byClass;
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'seed' => $this->seed,
            'entries' => \array_map(
                static fn(PlanEntry $entry): array => $entry->toWire(),
                $this->entries,
            ),
        ];
    }

    /**
     * @throws \InvalidArgumentException when entries are not grouped by class
     */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        $entries = [];

        foreach (Wire::listOfMaps($payload, 'entries') as $entry) {
            $entries[] = PlanEntry::fromWire($entry);
        }

        return new self($entries, Wire::nullableInt($payload, 'seed'));
    }
}
