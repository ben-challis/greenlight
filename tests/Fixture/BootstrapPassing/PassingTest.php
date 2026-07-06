<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\BootstrapPassing;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Attribute\Test;

final class PassingTest
{
    #[Before]
    public function logBefore(): void
    {
        $this->log('before');
    }

    #[Test]
    public function passes(): void
    {
        $this->log('test');
    }

    #[After]
    public function logAfter(): void
    {
        $this->log('after');
    }

    private function log(string $entry): void
    {
        $file = \getenv('GREENLIGHT_FIXTURE_LOG');

        if (\is_string($file) && $file !== '') {
            \file_put_contents($file, $entry . "\n", \FILE_APPEND);
        }
    }
}
