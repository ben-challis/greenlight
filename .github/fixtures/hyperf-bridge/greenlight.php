<?php

declare(strict_types=1);

use App\DisposalProbe;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Hyperf\ContainerLifetime;
use Greenlight\Hyperf\HyperfPlugin;
use Psr\Container\ContainerInterface;

$mode = \getenv('GREENLIGHT_HYPERF_CONTAINER_LIFETIME') ?: 'worker';
$lifetime = match ($mode) {
    'worker' => ContainerLifetime::Worker,
    'attempt' => ContainerLifetime::TestAttempt,
    default => throw new \RuntimeException('The Hyperf acceptance container lifetime is invalid.'),
};
$marker = __DIR__ . '/runtime/lifecycle-' . $mode . '.json';
@\unlink($marker);
$record = static function (ContainerInterface $container) use ($marker): void {
    $probe = $container->get(DisposalProbe::class);
    $json = \json_encode($probe->snapshot(), \JSON_THROW_ON_ERROR);

    if (\file_put_contents($marker, $json) === false) {
        throw new \RuntimeException('The Hyperf acceptance fixture did not write its lifecycle marker.');
    }
};

return GreenlightConfig::create()
    ->paths([__DIR__ . '/tests/' . \ucfirst($mode)])
    ->workers(1)
    ->plugins(new HyperfPlugin(
        __DIR__,
        containerLifetime: $lifetime,
        reset: static function (ContainerInterface $container) use ($record): void {
            $container->get(DisposalProbe::class)->reset();
            $record($container);
        },
        dispose: static function (ContainerInterface $container) use ($record): void {
            $container->get(DisposalProbe::class)->dispose();
            $record($container);
        },
    ));
