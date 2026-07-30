<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Expect\Expect;

final class ThrowableDetailWireContractTest
{
    #[Test]
    public function wirePayloadPreservesEveryThrowableField(): void
    {
        $detail = new ThrowableDetail(
            \RuntimeException::class,
            'Connection failed.',
            '/project/src/Client.php',
            42,
            [
                'App\Client::connect at /project/src/Client.php:42',
                'App\Service::request at /project/src/Service.php:18',
            ],
        );

        Expect::that($detail->toWire())
            ->because('the wire payload MUST preserve each throwable diagnostic field')
            ->toBe([
                'class' => \RuntimeException::class,
                'message' => 'Connection failed.',
                'file' => '/project/src/Client.php',
                'line' => 42,
                'stackFrames' => [
                    'App\Client::connect at /project/src/Client.php:42',
                    'App\Service::request at /project/src/Service.php:18',
                ],
            ]);
    }
}
