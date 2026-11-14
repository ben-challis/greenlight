<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Hyperf;

use Greenlight\Attribute\Test;
use Greenlight\Hyperf\CoroutineOutcome;
use Greenlight\Hyperf\HyperfBridgeError;

use function Greenlight\expect;

final readonly class CoroutineOutcomeTest
{
    #[Test]
    public function aStartedCoroutineWithoutAResultIsReportedAsBlocked(): void
    {
        expect()->calling(static fn(): mixed => CoroutineOutcome::value(true, self::result(null), null))
            ->toThrow(HyperfBridgeError::class, matching: '/started but did not finish/');
    }

    #[Test]
    public function aCoroutineThatDidNotStartIsReportedAsAStartFailure(): void
    {
        expect()->calling(static fn(): mixed => CoroutineOutcome::value(false, self::result(null), null))
            ->toThrow(HyperfBridgeError::class, message: 'Swoole did not start the test coroutine. Check the Swoole runtime configuration.');
    }

    #[Test]
    public function theCoroutineFailureTakesPrecedenceOverAnEmptyResult(): void
    {
        $failure = new \RuntimeException('The attempt failed.');

        expect()->calling(static fn(): mixed => CoroutineOutcome::value(true, self::result(null), $failure))
            ->toThrow($failure);
    }

    #[Test]
    public function returnsTheCoroutineValue(): void
    {
        expect(CoroutineOutcome::value(true, ['value' => null], null))->toBeNull();
        expect(CoroutineOutcome::value(true, self::result('done'), null))->toBe('done');
    }

    /** @return array{value: string}|array{} */
    private static function result(?string $value): array
    {
        return $value === null ? [] : ['value' => $value];
    }
}
