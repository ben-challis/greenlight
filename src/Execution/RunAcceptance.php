<?php

declare(strict_types=1);

namespace Greenlight\Execution;

use Greenlight\Config\ExecutionConfiguration;
use Greenlight\Execution\Plugin\PluginRuntimeError;
use Greenlight\Execution\Plugin\RunPolicyRuntime;
use Greenlight\Result\ResultSummary;

/**
 * Evaluates run acceptance through the execution plugin seam.
 *
 * @internal
 */
final class RunAcceptance
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param non-negative-int $retriedPasses
     * @return list<non-empty-string>
     * @throws RunPolicyError
     */
    public static function failureMessages(
        ExecutionConfiguration $execution,
        ResultSummary $summary,
        int $retriedPasses,
    ): array {
        try {
            return RunPolicyRuntime::fromDefinitions(
                $execution->plugins,
                $execution->runPolicy,
            )->failureMessages($summary, $retriedPasses);
        } catch (PluginRuntimeError $error) {
            throw RunPolicyError::fromRuntime($error);
        }
    }
}
