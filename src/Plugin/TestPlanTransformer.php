<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Removes or reorders selected tests before a run starts.
 * Each replacement can contain only tests from the plan the plugin receives.
 * A later transformer cannot restore a test that an earlier transformer removed.
 */
interface TestPlanTransformer extends Plugin
{
    public function transformTestPlan(TestPlan $plan): TestPlan;
}
