<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanIdeHelperDnf;

use Greenlight\Doubles\Fake;
use Greenlight\Expect\ExpectationExtension;

final class DnfExtension implements ExpectationExtension, Fake
{
    /** @return array{toCompareWith: \Closure(mixed, (\Countable&\Iterator<mixed, mixed>)|string): bool} */
    #[\Override]
    public function matchers(): array
    {
        return [
            'toCompareWith' => static fn(
                mixed $subject,
                (\Countable&\Iterator)|string $comparison,
            ): bool => $subject === $comparison,
        ];
    }
}
