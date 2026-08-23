<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\CleanupExpectationAfterError;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\Cleanup;

final readonly class CleanupExpectationAfterErrorTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function errorsBeforeCleanupExpectationFails(): never
    {
        $this->cleanup->defer(static function (): void {
            Expect::that('actual')->toBe('expected');
        });

        throw new \RuntimeException('test broke first');
    }
}
