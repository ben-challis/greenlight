<?php

declare(strict_types=1);

namespace Greenlight\Runner\Execution;

use Greenlight\Config\Configuration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Wire\WireCommunicationFailed;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Worker\EventSink;

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
        Configuration $configuration,
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
