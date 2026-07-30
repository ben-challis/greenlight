<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Tests\Support\JsonWire;

final class HelloTest
{
    #[Test]
    #[DataSet('validWorkerIntroductions')]
    public function zeroWorkerIdentitiesSurviveTheWire(string $workerId, string $token): void
    {
        $hello = new Hello($workerId, $token, 1);
        $decoded = Hello::fromWire(JsonWire::roundTrip($hello->toWire()));

        Expect::that($hello->workerId)
            ->because('a worker introduction MUST retain each non-empty worker ID')
            ->toBe($workerId)
            ->and($hello->token)
            ->because('a worker introduction MUST retain each non-empty authentication token')
            ->toBe($token)
            ->and($decoded->toWire())
            ->because('the worker introduction MUST survive the wire')
            ->toBe($hello->toWire());
    }

    #[Test]
    #[DataSet('invalidWorkerIntroductions')]
    public function invalidWorkerIntroductionsAreRejected(
        string $workerId,
        string $token,
        int $pid,
        string $message,
    ): void {
        Expect::that(static fn(): Hello => new Hello($workerId, $token, $pid))
            ->because('worker introductions MUST identify an authenticated operating-system process')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validWorkerIntroductions(): iterable
    {
        yield 'zero worker ID' => ['0', 'token'];

        yield 'zero authentication token' => ['worker-1', '0'];
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function invalidWorkerIntroductions(): iterable
    {
        yield 'empty worker ID' => [
            '',
            'token',
            1,
            'Hello messages require a nonempty worker ID.',
        ];
        yield 'empty authentication token' => [
            'worker-1',
            '',
            1,
            'Hello messages require a nonempty authentication token.',
        ];
        yield 'zero process ID' => [
            'worker-1',
            'token',
            0,
            'Hello messages require a positive process ID. Actual value: 0.',
        ];
        yield 'negative process ID' => [
            'worker-1',
            'token',
            -7,
            'Hello messages require a positive process ID. Actual value: -7.',
        ];
    }
}
