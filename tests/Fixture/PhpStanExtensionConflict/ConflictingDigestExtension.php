<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanExtensionConflict;

use Greenlight\Doubles\Fake;
use Greenlight\Expect\ExpectationExtension;

final class ConflictingDigestExtension implements ExpectationExtension, Fake
{
    /** @return array{toHaveDigestLength: \Closure(mixed, string): bool} */
    #[\Override]
    public function matchers(): array
    {
        return [
            'toHaveDigestLength' => static fn(mixed $subject, string $length): bool => \is_string($subject)
                && \strlen($subject) === (int) $length,
        ];
    }
}
