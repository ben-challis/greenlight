<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Result\TestResult;
use Greenlight\Test\TestDefinition;

/**
 * Transforms a test result after retries and test-scope teardown complete.
 *
 * Greenlight runs lower priorities first. It uses registration order for
 * equal priorities. Greenlight calls each transformer one time for each
 * executed test, before the worker finalizes the class scope and publishes the
 * result.
 *
 * A plugin can return the same result or a replacement. Preserve the test
 * identity. Use `TestResult::withOutcome()` for outcome changes so that the
 * result records their source. Greenlight contains transformer failures and
 * continues with the remaining transformers.
 */
interface TerminalResultTransformer extends Plugin
{
    public function transformTerminalResult(TestDefinition $definition, TestResult $result): TestResult;
}
