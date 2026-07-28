<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\Doubles\ProtectedPropertyContract;

final readonly class ProxyProtectedPropertyTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function generatedProxiesPreserveProtectedAbstractProperties(): void
    {
        $doubles = new Doubles($this->tempDirectory->subdirectory('proxies'));
        $double = $doubles->stub(ProtectedPropertyContract::class);

        try {
            $property = new \ReflectionProperty($double, 'status');

            Expect::that($property->isProtected())
                ->because('a proxy property MUST preserve protected visibility')
                ->toBeTrue()
                ->and((string) $property->getType())
                ->toBe('string');
        } finally {
            $doubles->dispose();
        }
    }
}
