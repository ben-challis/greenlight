<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/** Changes the selected tests or their execution order before a run starts. */
interface TestPlanTransformer extends Plugin
{
    public function transformTestPlan(TestPlan $plan): TestPlan;
}
