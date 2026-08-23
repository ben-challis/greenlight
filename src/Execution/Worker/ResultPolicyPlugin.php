<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

use Greenlight\Plugin\TerminalResultTransformer;
use Greenlight\Result\ResultPolicy;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestDefinition;

/**
 * Applies the configured result policy through the terminal-result plugin
 * capability.
 *
 * @internal
 */
final readonly class ResultPolicyPlugin implements TerminalResultTransformer
{
    public function __construct(private ResultPolicy $policy) {}

    #[\Override]
    public function transformTerminalResult(TestDefinition $definition, TestResult $result): TestResult
    {
        return $this->policy->apply($result);
    }
}
