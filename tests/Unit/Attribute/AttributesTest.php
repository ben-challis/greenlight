<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Attribute;

use Greenlight\Attribute\Before;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class AttributesTest
{
    private bool $beforeRan = false;

    #[Before]
    public function markBefore(): void
    {
        $this->beforeRan = true;
    }

    #[Test]
    public function beforeHookRunsBeforeTests(): void
    {
        Expect::that($this->beforeRan)->because('before hook runs before tests')->toBeTrue();
    }
}
