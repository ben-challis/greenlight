<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanExtension;

use Greenlight\Expect\ExpectationExtension;

final class DigestExtension implements ExpectationExtension
{
    #[\Override]
    public function matchers(): array
    {
        return [
            'toBeHexadecimal' => static fn(mixed $subject): bool => \is_string($subject)
                && \preg_match('/^[0-9a-f]+$/', $subject) === 1,
            'toHaveDigestLength' => static fn(mixed $subject, int $length): bool => \is_string($subject)
                && \strlen($subject) === $length,
        ];
    }
}
