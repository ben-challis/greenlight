<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Doubles\ProtectedPropertyContract;

use function Greenlight\expect;

final readonly class ProxyProtectedPropertyTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function generatedProxiesPreserveProtectedAbstractProperties(): void
    {
        $doubles = new Doubles($this->tempDirectory->subdirectory('proxies'));
        $double = $doubles->stub(ProtectedPropertyContract::class);

        try {
            $property = new \ReflectionProperty($double, 'status');

            expect($property->isProtected())
                ->because('a proxy property MUST preserve protected visibility')
                ->toBeTrue();
            expect((string) $property->getType())
                ->toBe('string');
        } finally {
            $doubles->dispose();
        }
    }
}
