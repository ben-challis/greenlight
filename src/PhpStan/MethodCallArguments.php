<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Attribute\CoverageIgnore;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;

/**
 * Finds an explicit method argument by name or position.
 *
 * @internal
 */
final class MethodCallArguments
{
    #[CoverageIgnore]
    private function __construct() {}

    public static function find(MethodCall $call, string $name, int $position): ?Arg
    {
        $nextPosition = 0;

        foreach ($call->getArgs() as $argument) {
            if ($argument->unpack) {
                continue;
            }

            if ($argument->name instanceof Identifier) {
                if ($argument->name->toString() === $name) {
                    return $argument;
                }

                continue;
            }

            if ($nextPosition === $position) {
                return $argument;
            }

            ++$nextPosition;
        }

        return null;
    }
}
