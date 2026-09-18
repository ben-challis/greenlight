<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\Test;
use Greenlight\Result\ThrowableDetail;

use function Greenlight\expect;

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

        expect($detail->toWire())
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
