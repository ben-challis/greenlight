<?php

declare(strict_types=1);

namespace Greenlight\Reporting\Profile;

use Greenlight\Event\Event;
use Greenlight\Event\RunFinished;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Event\WorkerSpawned;
use Greenlight\Event\WorkerTiming;
use Greenlight\Reporting\Plural;
use Greenlight\Reporting\Style;

/**
 * Makes a run profile only from the event stream.
 *
 * Thus, a live run and a saved JSONL artifact produce the same values.
 *
 * Class events use worker clocks. Worker and run events use orchestrator
 * clocks. Detailed worker timings use the orchestrator monotonic clock.
 *
 * Worker busy time is the sum of its test-class periods. Utilization is busy
 * time divided by the worker period for a non-isolated worker. Boot latency is
 * the time from process start to the first test class. Legacy streams use this
 * broad latency when they have no detailed worker timing data.
 *
 * @internal
 */
final class ProfileAggregator
{
    private const int SLOWEST_LIMIT = 10;

    /**
     * @var array<string, WorkerProfile>
     */
    private array $workers = [];

    /**
     * @var array<string, float>
     */
    private array $classDurations = [];

    private ?RunStarted $runStarted = null;

    private ?RunFinished $runFinished = null;

    public function onEvent(Event $event): void
    {
        if ($event instanceof RunStarted) {
            $this->runStarted = $event;

            return;
        }

        if ($event instanceof WorkerSpawned) {
            $this->worker($event->workerId)->spawned($event->occurredAt);

            return;
        }

        if ($event instanceof TestClassStarted && $event->workerId !== '') {
            $this->worker($event->workerId)->classStarted($event->occurredAt, $event->isolated);

            return;
        }

        if ($event instanceof TestClassFinished && $event->workerId !== '') {
            $span = $this->worker($event->workerId)->classFinished($event->occurredAt);

            if ($span !== null) {
                $this->classDurations[$event->class] = ProfileDuration::add(
                    $this->classDurations[$event->class] ?? 0.0,
                    $span,
                );
            }

            return;
        }

        if ($event instanceof RunFinished) {
            $this->runFinished = $event;
        }
    }

    /**
     * Returns the profile block or an empty string if no run completed.
     */
    public function render(Style $style): string
    {
        if (!$this->runFinished instanceof RunFinished) {
            return '';
        }

        $lines = ["\nProfile:"];
        $spawned = \count(\array_filter($this->workers, static fn(WorkerProfile $worker): bool => $worker->spawnedAt !== null));
        $isolated = \count(\array_filter($this->workers, static fn(WorkerProfile $worker): bool => $worker->isolated));
        $workerSummary = \sprintf(
            '  Workers: %d requested, %d spawned',
            $this->runStarted instanceof RunStarted ? $this->runStarted->workers : 0,
            $spawned,
        );

        if ($isolated > 0) {
            $workerSummary .= \sprintf(', %d isolated', $isolated);
        }

        $lines[] = $workerSummary;

        if ($this->runFinished->workerTimings !== []) {
            foreach ($this->timingLines($this->runFinished->workerTimings) as $line) {
                $lines[] = $line;
            }
        }

        $bootLatencies = [];
        $finishTimes = [];

        foreach ($this->workers as $id => $worker) {
            $bootLatency = $worker->bootLatency();

            if ($bootLatency !== null) {
                $bootLatencies[] = $bootLatency;
            }

            if ($worker->lastFinishAt !== null) {
                $finishTimes[$id] = $worker->lastFinishAt;
            }
        }

        if ($this->runFinished->workerTimings === [] && $bootLatencies !== []) {
            $lines[] = \sprintf(
                '  Boot latency: %.3fs average (spawn to first class, %s)',
                ProfileDuration::average($bootLatencies),
                Plural::count(\count($bootLatencies), 'worker'),
            );
        }

        $rows = [];

        foreach ($this->workers as $id => $worker) {
            if ($worker->classes === 0) {
                continue;
            }

            $rows[] = [
                $id,
                (string) $worker->classes,
                \sprintf('%.3fs', $worker->busy),
                $worker->isolated ? null : $worker->utilizationPercent(),
                $worker->isolated,
            ];
        }

        if ($rows !== []) {
            $lines[] = '';

            foreach ($this->workerTable($style, $rows, $isolated > 0) as $line) {
                $lines[] = $line;
            }
        }

        if (\count($finishTimes) > 1) {
            $lines[] = \sprintf(
                '  Makespan spread: %.3fs between first and last worker finish',
                ProfileDuration::between(\min($finishTimes), \max($finishTimes)),
            );
        }

        if ($this->classDurations !== []) {
            \arsort($this->classDurations);
            $lines[] = '';
            $lines[] = '  Slowest classes:';
            $slowest = \array_slice($this->classDurations, 0, self::SLOWEST_LIMIT, preserve_keys: true);
            // 6 is the floor a %.3fs render can occupy (0.000s).
            $width = \max(6, ...\array_map(static fn(float $duration): int => \strlen(\sprintf('%.3fs', $duration)), \array_values($slowest)));

            foreach ($slowest as $class => $duration) {
                // Add space outside the color codes. Thus, escape sequences
                // cannot change the alignment.
                $pad = \str_repeat(' ', $width - \strlen(\sprintf('%.3fs', $duration)));
                $lines[] = \sprintf('    %s%s  %s', $pad, $style->duration($duration), $class);
            }
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * Returns detailed worker lifecycle and idle timing lines.
     *
     * @param list<WorkerTiming> $timings
     *
     * @return list<string>
     */
    private function timingLines(array $timings): array
    {
        $spawnToHello = [];
        $helloToReady = [];
        $readyToAssignment = [];
        $retirementToExit = [];
        $assignmentGaps = 0;
        $assignmentGapSeconds = 0.0;
        $bootstrapBarrierSeconds = 0.0;
        $resourceCapacitySeconds = 0.0;
        $noQueuedWorkSeconds = 0.0;

        foreach ($timings as $timing) {
            if ($timing->spawnToHelloSeconds !== null) {
                $spawnToHello[] = $timing->spawnToHelloSeconds;
            }

            if ($timing->helloToReadySeconds !== null) {
                $helloToReady[] = $timing->helloToReadySeconds;
            }

            if ($timing->readyToFirstAssignmentSeconds !== null) {
                $readyToAssignment[] = $timing->readyToFirstAssignmentSeconds;
            }

            if ($timing->retirementToExitSeconds !== null) {
                $retirementToExit[] = $timing->retirementToExitSeconds;
            }

            $assignmentGaps += $timing->assignmentGaps;
            $assignmentGapSeconds = ProfileDuration::add($assignmentGapSeconds, $timing->assignmentGapSeconds);
            $bootstrapBarrierSeconds = ProfileDuration::add($bootstrapBarrierSeconds, $timing->bootstrapBarrierSeconds);
            $resourceCapacitySeconds = ProfileDuration::add($resourceCapacitySeconds, $timing->resourceCapacitySeconds);
            $noQueuedWorkSeconds = ProfileDuration::add($noQueuedWorkSeconds, $timing->noQueuedWorkSeconds);
        }

        $lines = ['  Startup phases:'];

        foreach ([
            ['Spawn to hello', $spawnToHello],
            ['Hello to ready (bootstrap)', $helloToReady],
            ['Ready to first assignment', $readyToAssignment],
        ] as [$label, $durations]) {
            if ($durations === []) {
                continue;
            }

            $lines[] = \sprintf(
                '    %s: %.3fs average (%s)',
                $label,
                ProfileDuration::average($durations),
                Plural::count(\count($durations), 'worker'),
            );
        }

        $lines[] = \sprintf(
            '  Assignment gaps: %.3fs total (%s)',
            $assignmentGapSeconds,
            Plural::count($assignmentGaps, 'gap'),
        );
        $lines[] = '  Idle attribution:';
        $lines[] = \sprintf('    Bootstrap barrier: %.3fs total', $bootstrapBarrierSeconds);
        $lines[] = \sprintf('    Resource capacity: %.3fs total', $resourceCapacitySeconds);
        $lines[] = \sprintf('    No queued work: %.3fs total', $noQueuedWorkSeconds);

        if ($retirementToExit !== []) {
            $lines[] = \sprintf(
                '  Retirement request to exit observed: %.3fs average (%s)',
                ProfileDuration::average($retirementToExit),
                Plural::count(\count($retirementToExit), 'worker'),
            );
        }

        return $lines;
    }

    /**
     * Returns the worker statistics table.
     *
     * Worker IDs align left, and numeric columns align right. The data determines
     * the column widths.
     *
     * @param list<array{string, string, string, ?int, bool}> $rows
     *
     * @return list<string>
     */
    private function workerTable(Style $style, array $rows, bool $showIsolation): array
    {
        $workerWidth = \max(\strlen('Worker'), ...\array_map(static fn(array $row): int => \strlen($row[0]), $rows));
        $classesWidth = \max(\strlen('Classes'), ...\array_map(static fn(array $row): int => \strlen($row[1]), $rows));
        $busyWidth = \max(\strlen('Busy'), ...\array_map(static fn(array $row): int => \strlen($row[2]), $rows));
        $utilWidth = \max(\strlen('Util'), ...\array_map(static fn(array $row): int => \strlen($row[3] . '%'), $rows));

        $lines = [\rtrim(\sprintf(
            '  %s  %s  %s  %s%s',
            \str_pad('Worker', $workerWidth),
            \str_pad('Classes', $classesWidth, ' ', \STR_PAD_LEFT),
            \str_pad('Busy', $busyWidth, ' ', \STR_PAD_LEFT),
            \str_pad('Util', $utilWidth, ' ', \STR_PAD_LEFT),
            $showIsolation ? '  Isolated' : '',
        ))];

        foreach ($rows as [$id, $classes, $busy, $percent, $isolated]) {
            // Add space outside the color codes. Thus, escape sequences cannot
            // change the alignment.
            $util = $percent === null
                ? \str_repeat(' ', $utilWidth)
                : \str_repeat(' ', $utilWidth - \strlen($percent . '%')) . $this->utilization($style, $percent);

            $lines[] = \rtrim(\sprintf(
                '  %s  %s  %s  %s%s',
                \str_pad($id, $workerWidth),
                \str_pad($classes, $classesWidth, ' ', \STR_PAD_LEFT),
                \str_pad($busy, $busyWidth, ' ', \STR_PAD_LEFT),
                $util,
                $showIsolation ? '  ' . ($isolated ? 'yes' : '') : '',
            ));
        }

        return $lines;
    }

    /**
     * Uses green for utilization of 90 percent or more. Uses yellow from 70
     * percent. Uses red below 70 percent. Thus, idle workers are easy to see.
     */
    private function utilization(Style $style, int $percent): string
    {
        $text = $percent . '%';

        if ($percent >= 90) {
            return $style->ok($text);
        }

        if ($percent >= 70) {
            return $style->warn($text);
        }

        return $style->error($text);
    }

    private function worker(string $id): WorkerProfile
    {
        return $this->workers[$id] ??= new WorkerProfile();
    }
}
