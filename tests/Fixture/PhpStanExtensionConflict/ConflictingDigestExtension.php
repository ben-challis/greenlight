<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanExtensionConflict;

use Greenlight\Expect\ExpectationExtension;

final class ConflictingDigestExtension implements ExpectationExtension
{
    #[\Override]
    public function matchers(): array
    {
        return [
            'toHaveDigestLength' => static fn(mixed $subject, string $length): bool => \is_string($subject)
                && \strlen($subject) === (int) $length,
        ];
    }
}
