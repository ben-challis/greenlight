<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStan;

final class MatcherTypeShapes
{
    /**
     * @param mixed $subject
     */
    public function untyped($subject): void {}

    public function union(string|int $subject): void {}

    /**
     * @param \Countable&\Iterator<int, mixed> $subject
     */
    public function intersection(\Countable&\Iterator $subject): void {}
}
