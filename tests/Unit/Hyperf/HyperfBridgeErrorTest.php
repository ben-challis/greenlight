<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Hyperf;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Hyperf\HyperfBridgeError;

final readonly class HyperfBridgeErrorTest
{
    /** @param \Closure(): HyperfBridgeError $factory */
    #[Test]
    #[DataSet('diagnostics')]
    public function factoriesPreserveActionableDiagnostics(\Closure $factory, string $message): void
    {
        Expect::that($factory()->getMessage())->toBe($message);
    }

    /** @return iterable<string, array{\Closure(): HyperfBridgeError, string}> */
    public static function diagnostics(): iterable
    {
        yield 'missing base path' => [
            static fn(): HyperfBridgeError => HyperfBridgeError::basePathMissing('/app'),
            'The Hyperf base path "/app" does not exist. Give HyperfPlugin the application root directory.',
        ];
        yield 'conflicting base path' => [
            static fn(): HyperfBridgeError => HyperfBridgeError::basePathConflict('/app', '/other'),
            'HyperfPlugin uses base path "/app", but BASE_PATH is already "/other". Use one Hyperf application in each worker.',
        ];
        yield 'missing container file' => [
            static fn(): HyperfBridgeError => HyperfBridgeError::containerFileMissing('/app/config/container.php'),
            'The Hyperf container file "/app/config/container.php" does not exist. Add the standard config/container.php file.',
        ];
        yield 'unavailable framework' => [
            HyperfBridgeError::frameworkUnavailable(...),
            'HyperfPlugin requires hyperf/framework and hyperf/di 3.2. Install both packages before you activate the plugin.',
        ];
        yield 'unsupported framework' => [
            static fn(): HyperfBridgeError => HyperfBridgeError::frameworkVersionUnsupported('3.1.0'),
            'HyperfPlugin found hyperf/framework "3.1.0", but it requires version 3.2. Install hyperf/framework 3.2.',
        ];
        yield 'unavailable Swoole' => [
            HyperfBridgeError::swooleUnavailable(...),
            'HyperfPlugin requires the Swoole extension. Install Swoole 5 or later to run tests in Hyperf coroutines.',
        ];
        yield 'unavailable pcntl' => [
            HyperfBridgeError::pcntlUnavailable(...),
            'HyperfPlugin requires the pcntl extension for the Hyperf class scan. Enable pcntl for the Greenlight PHP command.',
        ];
        yield 'unsupported Swoole' => [
            static fn(): HyperfBridgeError => HyperfBridgeError::swooleVersionUnsupported('4.8.0'),
            'HyperfPlugin found Swoole "4.8.0", but it requires major version 5 or later.',
        ];
        yield 'unavailable scan lock' => [
            static fn(): HyperfBridgeError => HyperfBridgeError::scanLockUnavailable('/app/runtime/container/greenlight.scan.lock'),
            'HyperfPlugin cannot open scan lock "/app/runtime/container/greenlight.scan.lock". Make the runtime/container directory writable.',
        ];
        yield 'failed scan lock' => [
            static fn(): HyperfBridgeError => HyperfBridgeError::scanLockFailed('/app/runtime/container/greenlight.scan.lock'),
            'HyperfPlugin cannot lock "/app/runtime/container/greenlight.scan.lock" for the class scan.',
        ];
        yield 'invalid container' => [
            static fn(): HyperfBridgeError => HyperfBridgeError::notAContainer('/app/config/container.php', 'string'),
            'The Hyperf container file "/app/config/container.php" returned "string". It must return a Psr\\Container\\ContainerInterface instance.',
        ];
        yield 'reused container' => [
            HyperfBridgeError::reusedContainer(...),
            'The Hyperf container file returned the previous test container. It must create a new container for each test.',
        ];
        yield 'invalid application' => [
            static fn(): HyperfBridgeError => HyperfBridgeError::applicationUnavailable('null'),
            'The Hyperf application binding returned "null". The binding must return an application object.',
        ];
        yield 'coroutine start failure' => [
            HyperfBridgeError::coroutineDidNotStart(...),
            'Swoole did not start the test coroutine. Check the Swoole runtime configuration.',
        ];
        yield 'missing worker container' => [
            HyperfBridgeError::workerContainerUnavailable(...),
            'The Hyperf worker container is not available. Greenlight cannot start the worker runtime.',
        ];
        yield 'missing worker runtime' => [
            HyperfBridgeError::workerRuntimeUnavailable(...),
            'The Hyperf worker runtime is not active. Run the test attempt inside WorkerRuntimeRunner.',
        ];
        yield 'inactive attempt container' => [
            HyperfBridgeError::containerOutsideAttempt(...),
            'The Hyperf container is not active. Resolve Hyperf services only during a Greenlight test attempt.',
        ];
        yield 'unknown service' => [
            static fn(): HyperfBridgeError => HyperfBridgeError::unknownServiceId('clock', \DateTimeInterface::class),
            'The Hyperf container has no service "clock" for type "DateTimeInterface". Check the service ID.',
        ];
        yield 'service type mismatch' => [
            static fn(): HyperfBridgeError => HyperfBridgeError::serviceTypeMismatch('clock', \DateTimeInterface::class, 'stdClass'),
            'The Hyperf service "clock" has type "stdClass". The parameter requires type "DateTimeInterface".',
        ];
    }
}
