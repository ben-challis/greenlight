<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

use Greenlight\Event\Event;
use Greenlight\Result\TestResult;
use Greenlight\Test\RetryPolicy;
use Greenlight\Doubles\Fake;
use Greenlight\Harness\ServiceResolution;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Plugin\AfterTestSubscriber;
use Greenlight\Plugin\BeforeTestSubscriber;
use Greenlight\Plugin\RetryDecider;
use Greenlight\Plugin\RunLifecycleSubscriber;
use Greenlight\Plugin\TestContext;

class FakeCapabilityPlugin implements AfterTestSubscriber, BeforeTestSubscriber, Fake, RetryDecider, RunLifecycleSubscriber, ServiceResolver
{
    #[\Override]
    public function shouldRetry(RetryPolicy $policy, TestResult $result, int $attempt, ?\Throwable $cause): bool
    {
        return false;
    }

    #[\Override]
    public function onRunEvent(Event $event): void {}

    #[\Override]
    public function resolve(string $type, array $attributes): ServiceResolution
    {
        return ServiceResolution::unhandled();
    }

    #[\Override]
    public function beforeTest(TestContext $context): void {}

    #[\Override]
    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        return $result;
    }
}
