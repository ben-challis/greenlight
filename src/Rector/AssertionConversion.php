<?php

declare(strict_types=1);

namespace Greenlight\Rector;

/**
 * Describes how one PHPUnit assertion maps onto a Greenlight expectation.
 * The entry selects the subject argument, the matcher arguments, and the
 * negation. Arguments beyond the arity form the PHPUnit failure message.
 * The converter rejects such messages unless drop_assertion_messages is enabled.
 * A manual migration can preserve a message with because().
 *
 * @internal
 */
final readonly class AssertionConversion
{
    /**
     * @param non-empty-string $matcher
     * @param int<0, max> $subject
     * @param list<int<0, max>> $matcherArguments
     * @param int<1, max> $arity
     */
    public function __construct(
        public string $matcher,
        public int $subject,
        public array $matcherArguments,
        public int $arity,
        public bool $negated = false,
    ) {}
}
