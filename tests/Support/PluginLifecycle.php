<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Plugin\TestContext;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;

final class PluginLifecycle
{
    public static function context(): TestContext
    {
        return new TestContext(
            new \stdClass(),
            new TestId('Fixture', 'probe'),
            new TestDefinition('Fixture', 'probe'),
            new HarnessScopes(new HarnessRegistry()),
        );
    }

    public static function passedResult(): TestResult
    {
        return new TestResult(new TestId('Fixture', 'probe'), Outcome::Passed, 0.0, 0);
    }

    private function __construct() {}
}
