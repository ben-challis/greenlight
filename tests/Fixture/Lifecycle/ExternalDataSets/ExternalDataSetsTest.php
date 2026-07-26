<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\ExternalDataSets;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class ExternalDataSetsTest
{
    #[Test]
    #[DataSet(SharedDataSets::class, 'words')]
    public function receivesWords(string $word): void
    {
        if (!\in_array($word, ['hello', 'goodbye'], true)) {
            throw new \RuntimeException(\sprintf('Unexpected word "%s".', $word));
        }
    }
}
