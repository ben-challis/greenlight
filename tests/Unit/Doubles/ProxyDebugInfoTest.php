<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Tests\Fixture\Doubles\DebugInfoContract;
use Greenlight\Tests\Fixture\Doubles\DebugInfoService;

use function Greenlight\expect;

final readonly class ProxyDebugInfoTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function debugOutputUsesTheClassImplementation(): void
    {
        $double = $this->doubles->mock(DebugInfoService::class);

        \ob_start();

        try {
            \var_dump($double);
        } finally {
            $output = (string) \ob_get_clean();
        }

        expect($output)
            ->because('a mock without a plan for __debugInfo() MUST support var_dump()')
            ->toContain('debug');
    }

    #[Test]
    public function abstractDebugInfoReturnsNoDetails(): void
    {
        $double = $this->doubles->mock(DebugInfoContract::class);

        expect($double->__debugInfo())
            ->because('an abstract __debugInfo() MUST NOT need a mock plan')
            ->toBe([]);
    }
}
