<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Test\ResourceName;

use function Greenlight\expect;

final class ResourceNameTest
{
    #[Test]
    #[DataSet('validNames')]
    public function acceptsCanonicalResourceNames(string $name): void
    {
        ResourceName::assertValid($name);

        expect(ResourceName::isValid($name))
            ->because('canonical resource names MUST be accepted')
            ->toBeTrue();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validNames(): iterable
    {
        yield 'single letter' => ['a'];
        yield 'digits' => ['123'];
        yield 'dot' => ['database.primary'];
        yield 'hyphen' => ['payment-sandbox'];
        yield 'underscore' => ['worker_1'];
        yield 'mixed separators' => ['cache.v2_local-read'];
    }

    #[Test]
    #[DataSet('invalidNames')]
    public function rejectsNonCanonicalResourceNames(string $name): void
    {
        expect(ResourceName::isValid($name))
            ->because('noncanonical resource names MUST be rejected')
            ->toBeFalse();
        expect()->calling(static fn() => ResourceName::assertValid($name))
            ->because('assertValid() MUST report the rejected resource name')
            ->toThrow(
                \InvalidArgumentException::class,
                message: \sprintf(
                    'Resource names must match %s, got "%s".',
                    ResourceName::PATTERN,
                    $name,
                ),
            );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Postgres'];
        yield 'leading separator' => ['-database'];
        yield 'slash' => ['database/primary'];
        yield 'space' => ['database primary'];
        yield 'trailing newline' => ["database\n"];
    }
}
