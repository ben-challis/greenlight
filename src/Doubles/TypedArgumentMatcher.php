<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Supplies a known accepted type for argument-plan validation.
 *
 * @template-covariant TValue = mixed
 * @extends ArgumentMatcher<TValue>
 *
 * @internal
 */
interface TypedArgumentMatcher extends ArgumentMatcher
{
    public function argumentType(): ?ArgumentType;
}
