<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;

final readonly class PlanEntryIdentityTest
{
    #[Test]
    #[DataSet('identityMismatches')]
    public function mismatchedIdentityNamesBothSides(
        string $idClass,
        string $idMethod,
        string $metadataClass,
        string $metadataMethod,
        string $message,
    ): void {
        Expect::that(static fn(): PlanEntry => new PlanEntry(
            new TestId($idClass, $idMethod),
            new TestMetadata($metadataClass, $metadataMethod),
        ))
            ->because('a plan identity mismatch MUST identify the test ID and its metadata')
            ->toThrow(
                \InvalidArgumentException::class,
                message: $message,
            );
    }

    /**
     * @return iterable<string, array{string, string, string, string, non-empty-string}>
     */
    public static function identityMismatches(): iterable
    {
        yield 'class and method' => [
            'App\PaymentTest',
            'chargesCard',
            'App\RefundTest',
            'refundsCard',
            'Plan entry identity App\PaymentTest::chargesCard does not match its metadata App\RefundTest::refundsCard.',
        ];

        yield 'method only' => [
            'App\FooTest',
            'a',
            'App\FooTest',
            'b',
            'Plan entry identity App\FooTest::a does not match its metadata App\FooTest::b.',
        ];
    }
}
