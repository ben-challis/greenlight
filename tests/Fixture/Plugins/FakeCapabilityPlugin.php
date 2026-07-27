<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Doubles\Fake;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Plugin\RetryDecider;
use Greenlight\Plugin\RunLifecycleSubscriber;
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\TestLifecycleSubscriber;

class FakeCapabilityPlugin implements Fake, RetryDecider, RunLifecycleSubscriber, ServiceResolver, TestLifecycleSubscriber
{
    #[\Override]
    public function shouldRetry(TestMetadata $metadata, TestResult $result, int $attempt, ?\Throwable $cause): bool
    {
        return false;
    }

    #[\Override]
    public function onRunEvent(Event $event): void {}

    #[\Override]
    public function resolve(string $type, array $attributes): ?object
    {
        return null;
    }

    #[\Override]
    public function beforeTest(TestContext $context): void {}

    #[\Override]
    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        return $result;
    }
}
