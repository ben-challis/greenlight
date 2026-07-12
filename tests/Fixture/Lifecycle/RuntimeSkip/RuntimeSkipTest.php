<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\RuntimeSkip;

use Greenlight\Attribute\Test;
use Greenlight\Core\SkipTest;

final class RuntimeSkipTest
{
    #[Test]
    public function decidesToSkipAtRuntime(): never
    {
        throw new SkipTest('the fixture backend is unreachable');
    }
}
