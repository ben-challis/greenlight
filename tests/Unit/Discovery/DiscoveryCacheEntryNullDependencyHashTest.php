<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCacheEntry;
use Greenlight\Expect\Expect;

final class DiscoveryCacheEntryNullDependencyHashTest
{
    #[Test]
    public function aNullDependencyContentHashIsRejected(): void
    {
        $decoded = [
            'mtime' => 100,
            'size' => 200,
            'entries' => [['class' => 'Example\Test']],
            'dependencies' => [
                '/project/tests/Provider.php' => [
                    'mtime' => 300,
                    'size' => 400,
                    'contentHash' => null,
                ],
            ],
        ];

        Expect::that(DiscoveryCacheEntry::fromDecoded($decoded))
            ->because('an explicit null dependency content hash is malformed')
            ->toBeNull();
    }
}
