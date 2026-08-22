<?php

declare(strict_types=1);

use App\DisposalProbe;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Hyperf\ContainerLifetime;
use Greenlight\Hyperf\HyperfPlugin;
use Psr\Container\ContainerInterface;

$configuredLifetime = \getenv('GREENLIGHT_HYPERF_CONTAINER_LIFETIME');
$mode = \is_string($configuredLifetime) && $configuredLifetime !== '' ? $configuredLifetime : 'worker';
$lifetime = match ($mode) {
    'worker' => ContainerLifetime::Worker,
    'attempt' => ContainerLifetime::TestAttempt,
    default => throw new \RuntimeException('The Hyperf acceptance container lifetime is invalid.'),
};
$marker = __DIR__ . '/runtime/lifecycle-' . $mode . '.json';
@\unlink($marker);
$probe = static function (ContainerInterface $container): DisposalProbe {
    $service = $container->get(DisposalProbe::class);

    if (!$service instanceof DisposalProbe) {
        throw new \RuntimeException('The Hyperf container MUST return DisposalProbe.');
    }

    return $service;
};
$record = static function (ContainerInterface $container) use ($marker, $probe): void {
    $json = \json_encode($probe($container)->snapshot(), \JSON_THROW_ON_ERROR);

    if (\file_put_contents($marker, $json) === false) {
        throw new \RuntimeException('The Hyperf acceptance fixture did not write its lifecycle marker.');
    }
};

return GreenlightConfig::create()
    ->paths([__DIR__ . '/tests/' . \ucfirst($mode)])
    ->workers(1)
    ->plugins(
        static fn(): HyperfPlugin => new HyperfPlugin(
            __DIR__,
            containerLifetime: $lifetime,
            reset: static function (ContainerInterface $container) use ($probe, $record): void {
                $probe($container)->reset();
                $record($container);
            },
            dispose: static function (ContainerInterface $container) use ($probe, $record): void {
                $probe($container)->dispose();
                $record($container);
            },
        ),
    );
