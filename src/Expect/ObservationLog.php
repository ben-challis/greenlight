<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Bounded rendered history for a failed temporal expectation.
 *
 * @internal
 */
final class ObservationLog
{
    private const int MAX_TAIL_GROUPS = 3;

    private const int MAX_RENDERED_BYTES = 2048;

    /**
     * @var array{at: float, value: string, repeats: positive-int}|null
     */
    private ?array $first = null;

    /**
     * @var list<array{at: float, value: string, repeats: positive-int}>
     */
    private array $tail = [];

    private int $observations = 0;

    private int $omittedGroups = 0;

    public function __construct(private readonly float $startedAt) {}

    public function record(float $at, string $value): void
    {
        ++$this->observations;
        $group = [
            'at' => \max(0.0, $at - $this->startedAt),
            'value' => $value,
            'repeats' => 1,
        ];

        if ($this->first === null) {
            $this->first = $group;

            return;
        }

        $lastIndex = \count($this->tail) - 1;

        if ($lastIndex >= 0 && $this->tail[$lastIndex]['value'] === $value) {
            ++$this->tail[$lastIndex]['repeats'];

            return;
        }

        if ($this->tail === [] && $this->first['value'] === $value) {
            ++$this->first['repeats'];

            return;
        }

        $this->tail[] = $group;

        if (\count($this->tail) > self::MAX_TAIL_GROUPS) {
            \array_shift($this->tail);
            ++$this->omittedGroups;
        }
    }

    /**
     * @return positive-int
     */
    public function count(): int
    {
        return \max(1, $this->observations);
    }

    public function render(): string
    {
        $groups = $this->first === null ? [] : [$this->first];

        foreach ($this->tail as $tail) {
            if ($groups !== [] && $tail === $groups[0]) {
                continue;
            }

            $groups[] = $tail;
        }

        $parts = \array_map(
            static fn(array $group): string => \sprintf(
                '+%.1fms %s%s',
                $group['at'] * 1000,
                $group['value'],
                $group['repeats'] > 1 ? \sprintf(' (×%d)', $group['repeats']) : '',
            ),
            $groups,
        );

        if ($this->omittedGroups > 0) {
            \array_splice($parts, 1, 0, \sprintf('... %d earlier changes omitted ...', $this->omittedGroups));
        }

        $rendered = \implode('; ', $parts);

        if (\strlen($rendered) <= self::MAX_RENDERED_BYTES) {
            return $rendered;
        }

        return \substr($rendered, 0, self::MAX_RENDERED_BYTES - 3) . '...';
    }
}
