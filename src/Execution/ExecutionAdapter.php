<?php

declare(strict_types=1);

namespace Greenlight\Execution;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Event\EventSink;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Internal\Wire\WireCommunicationFailed;
use Greenlight\Reporting\ReportGenerationFailed;

/**
 * Executes a coordinated plan through one execution method.
 *
 * @internal
 */
interface ExecutionAdapter
{
    /**
     * @param array<string, float> $classSeconds Recorded class durations.
     */
    public function topology(
        ExecutionPlan $plan,
        array $classSeconds,
    ): ExecutionTopology;

    /**
     * @throws AttachmentError
     * @throws ProtocolError
     * @throws ReportGenerationFailed
     * @throws WireCommunicationFailed
     */
    public function execute(
        ExecutionPlan $plan,
        EventSink $sink,
        ExecutionContext $context,
    ): ExecutionOutcome;
}
