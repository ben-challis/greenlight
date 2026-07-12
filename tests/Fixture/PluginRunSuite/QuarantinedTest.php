<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PluginRunSuite;

use Greenlight\Attribute\Group;
use Greenlight\Attribute\Test;

final class QuarantinedTest
{
    #[Test]
    public function passes(): void {}

    #[Test]
    #[Group('quarantined')]
    public function flakyAndQuarantined(): never
    {
        throw new \RuntimeException('known flaky');
    }
}
