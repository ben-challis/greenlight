<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

use Greenlight\Internal\Php\ErrorTrap;

/**
 * Prevents direct PHP stream writes from reaching in-process reporter streams
 * when a test disables output capture.
 *
 * @internal
 */
final class OutputSilencer
{
    private const string STREAM_FILTER = 'greenlight.output-discard';
    private static bool $registered = false;

    /** @var resource|null */
    private mixed $stdoutFilter = null;

    /** @var resource|null */
    private mixed $stderrFilter = null;

    /** @throws CaptureError */
    public function start(): void
    {
        if (!self::$registered
            && !\stream_filter_register(self::STREAM_FILTER, CapturedStreamFilter::class)
        ) {
            throw CaptureError::streamFilterUnavailable('STDOUT and STDERR');
        }

        self::$registered = true;

        $stdout = new OutputStreamBuffer(1);
        $stderr = new OutputStreamBuffer(1);
        $stdoutFilter = ErrorTrap::run(
            static fn() => \stream_filter_append(\STDOUT, self::STREAM_FILTER, \STREAM_FILTER_WRITE, $stdout),
        );
        $stderrFilter = ErrorTrap::run(
            static fn() => \stream_filter_append(\STDERR, self::STREAM_FILTER, \STREAM_FILTER_WRITE, $stderr),
        );

        if (!\is_resource($stdoutFilter) || !\is_resource($stderrFilter)) {
            if (\is_resource($stdoutFilter)) {
                ErrorTrap::run(static fn() => \stream_filter_remove($stdoutFilter));
            }

            if (\is_resource($stderrFilter)) {
                ErrorTrap::run(static fn() => \stream_filter_remove($stderrFilter));
            }

            $this->stop();

            throw CaptureError::streamFilterUnavailable('STDOUT and STDERR');
        }

        $this->stdoutFilter = $stdoutFilter;
        $this->stderrFilter = $stderrFilter;
    }

    public function stop(): void
    {
        foreach (['stderrFilter', 'stdoutFilter'] as $property) {
            $filter = $this->{$property};
            $this->{$property} = null;

            if (\is_resource($filter)) {
                ErrorTrap::run(static fn() => \stream_filter_remove($filter));
            }
        }
    }
}
