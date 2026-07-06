<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Bail;

use Greenlight\Attribute\Test;

final class BbTest
{
    #[Test]
    public function wouldAlsoPass(): void {}
}
