<?php

declare(strict_types=1);

namespace Greenlight\Runner\Execution;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Worker\EventSink;
use Greenlight\Wire\WireCommunicationFailed;

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
