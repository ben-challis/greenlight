<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Hyperf\ContainerLifetime;
use Greenlight\Hyperf\HyperfPlugin;

$configuredLifetime = \getenv('GREENLIGHT_HYPERF_CONTAINER_LIFETIME');
$lifetime = match ($configuredLifetime) {
    'attempt' => ContainerLifetime::TestAttempt,
    false, '', 'worker' => ContainerLifetime::Worker,
    default => throw new \RuntimeException('The Hyperf acceptance container lifetime is invalid.'),
};

return GreenlightConfig::create()
    ->paths([__DIR__ . '/tests/Blocked'])
    ->workers(1)
    ->plugins(static fn(): HyperfPlugin => new HyperfPlugin(__DIR__, containerLifetime: $lifetime));
