<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Expect\ExpectationExtensionError;

/**
 * Configured extension matchers have conflicting names or signatures.
 *
 * @internal
 */
final class MatcherMapError extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function invalidExtension(ExpectationExtensionError $error): self
    {
        return new self($error->getMessage(), $error);
    }

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
