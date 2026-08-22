<?php

declare(strict_types=1);

use Greenlight\Test\TestChannel;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Hyperf\HyperfBridgeError;
use Greenlight\Hyperf\HyperfPlugin;
use Greenlight\Plugin\WorkerBootstrapContext;

require __DIR__ . '/vendor/autoload.php';

$restricted = \dirname(__DIR__);
$temporaryDirectory = \sys_get_temp_dir();

if (\ini_set('open_basedir', __DIR__ . \PATH_SEPARATOR . $temporaryDirectory) === false) {
    throw new \RuntimeException('The Hyperf path verification did not set the filesystem restriction.');
}

$diagnostic = null;
$error = null;
\set_error_handler(static function (int $severity, string $message) use (&$diagnostic): bool {
    $diagnostic = $message;

    return true;
});

try {
    new HyperfPlugin($restricted)->onWorkerBootstrap(new WorkerBootstrapContext(
        'path-probe',
        new TestChannel(1),
        IntegrationResources::empty(),
    ));
} catch (HyperfBridgeError $caught) {
    $error = $caught;
} finally {
    \restore_error_handler();
}

if ($diagnostic !== null) {
    throw new \RuntimeException('The Hyperf path verification received an engine diagnostic: ' . $diagnostic);
}

$expected = HyperfBridgeError::basePathMissing($restricted)->getMessage();

if (!$error instanceof HyperfBridgeError || $error->getMessage() !== $expected) {
    throw new \RuntimeException('The Hyperf path verification did not receive the expected bridge error.');
}

\fwrite(STDOUT, "Verified restricted Hyperf path diagnostics.\n");
