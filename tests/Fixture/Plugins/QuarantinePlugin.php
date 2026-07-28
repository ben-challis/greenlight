<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Doubles\Fake;
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\TestLifecycleSubscriber;

final readonly class QuarantinePlugin implements TestLifecycleSubscriber, Fake
{
    #[\Override]
    public function beforeTest(TestContext $context): void {}

    #[\Override]
    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        if ($result->outcome->isSuccessful() || !\in_array('quarantined', $context->metadata->groups, true)) {
            return $result;
        }

        return $result->withOutcome(Outcome::Skipped, self::class);
    }
}
