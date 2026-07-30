<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\Doubles\AbstractProtectedMethodService;
use Greenlight\Tests\Fixture\Doubles\ProtectedMethodService;

final readonly class ProtectedMethodInterceptionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function classDoublesPreserveConcreteProtectedMethods(): void
    {
        $doubles = new Doubles($this->tempDirectory->subdirectory('proxies'));
        $double = $doubles->stub(ProtectedMethodService::class);

        try {
            Expect::that($double->message())
                ->because('a class double MUST preserve concrete protected implementation details')
                ->toBe('original message');
        } finally {
            $doubles->dispose();
        }
    }

    #[Test]
    public function classDoublesImplementAbstractProtectedMethods(): void
    {
        $doubles = new Doubles($this->tempDirectory->subdirectory('abstract-proxies'));

        try {
            Expect::that($doubles->stub(AbstractProtectedMethodService::class))
                ->because('a class double MUST implement an abstract protected method')
                ->toBeInstanceOf(AbstractProtectedMethodService::class);
        } finally {
            $doubles->dispose();
        }
    }
}
