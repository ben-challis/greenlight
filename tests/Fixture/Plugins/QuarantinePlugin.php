<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Doubles\Fake;
use Greenlight\Plugin\AfterTestSubscriber;
use Greenlight\Plugin\TestContext;

final readonly class QuarantinePlugin implements AfterTestSubscriber, Fake
{
    #[\Override]
    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        if ($result->outcome->isSuccessful() || !\in_array('quarantined', $context->definition->groups, true)) {
            return $result;
        }

        return $result->withOutcome(Outcome::Skipped, self::class);
    }
}
