<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\HarnessDisposalMatrix;

use Greenlight\Attribute\Test;

final readonly class HarnessDisposalMatrixTest
{
    public function __construct(private FailingHarnessService $service) {}

    #[Test]
    public function errorsBeforeDisposal(): never
    {
        $this->service->use();

        throw new \RuntimeException('test broke first');
    }

    #[Test]
    public function passesBeforeDisposal(): void
    {
        $this->service->use();
    }
}
