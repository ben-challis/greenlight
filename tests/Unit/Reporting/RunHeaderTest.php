<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\RunHeader;

final class RunHeaderTest
{
    #[Test]
    public function rendersEveryFieldWhenPresent(): void
    {
        $header = new RunHeader('0.4.0', 'greenlight.php', 123456, phpVersion: '8.3.1');

        Expect::that($header->render(11))
            ->toBe('Greenlight 0.4.0 | PHP 8.3.1 | config: greenlight.php | seed: 123456 | workers: 11');
    }

    #[Test]
    public function omitsAbsentFields(): void
    {
        $header = new RunHeader('dev-main', null, null, phpVersion: '8.4.0');

        Expect::that($header->render(1))
            ->toBe('Greenlight dev-main | PHP 8.4.0 | workers: 1');
    }

    #[Test]
    public function phpVersionDefaultsToTheRuntime(): void
    {
        $header = new RunHeader('dev-main');

        Expect::that($header->render(2))->toContain('PHP ' . \PHP_VERSION);
    }
}
