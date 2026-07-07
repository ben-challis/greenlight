<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

/**
 * The extension matcher maps of the given config files cannot be combined
 * into one static view.
 *
 * @internal
 */
final class MatcherMapError extends \RuntimeException
{
    public static function conflictingSignatures(
        string $matcher,
        string $firstFile,
        string $firstSignature,
        string $secondFile,
        string $secondSignature,
    ): self {
        return new self(\sprintf(
            'Extension matcher "%s" is declared with conflicting signatures: %s in "%s" and %s in "%s". '
            . 'Static analysis needs one signature per matcher name across all configured files.',
            $matcher,
            $firstSignature,
            $firstFile,
            $secondSignature,
            $secondFile,
        ));
    }
}
