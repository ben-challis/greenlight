<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Laravel\Service as LaravelService;
use Greenlight\Symfony\Service as SymfonyService;

final readonly class ServiceAttributeTest
{
    /**
     * @param class-string<LaravelService|SymfonyService> $attribute
     */
    #[Test]
    #[DataSet('serviceAttributes')]
    public function rejectsAnEmptyServiceIdentifier(string $attribute): void
    {
        Expect::that(static fn(): object => new $attribute(''))
            ->because('a service attribute MUST identify a container service')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Service identifier must not be empty.',
            );
    }

    /**
     * @param class-string<LaravelService|SymfonyService> $attribute
     */
    #[Test]
    #[DataSet('serviceAttributes')]
    public function preservesAZeroStringServiceIdentifier(string $attribute): void
    {
        Expect::that((new $attribute('0'))->id)
            ->because('a zero-string service identifier is not empty')
            ->toBe('0');
    }

    /**
     * @return iterable<string, array{class-string<LaravelService|SymfonyService>}>
     */
    public static function serviceAttributes(): iterable
    {
        yield 'Laravel' => [LaravelService::class];
        yield 'Symfony' => [SymfonyService::class];
    }
}
