<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\BridgeAttemptEnd;

use function Greenlight\expect;

final readonly class TempestRestoredEnvironmentProbe
{
    public const string ENVIRONMENT = 'before-tempest';

    public function seesTheRestoredEnvironment(): void
    {
        expect(\getenv('ENVIRONMENT'))->toBe(self::ENVIRONMENT);
    }
}
