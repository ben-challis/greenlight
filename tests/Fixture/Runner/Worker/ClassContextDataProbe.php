<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner\Worker;

final class ClassContextDataProbe
{
    public function accepts(): never
    {
        throw new \LogicException('The class-context probe MUST NOT run.');
    }

    /**
     * @return iterable<string, string>
     */
    public static function scalarRows(): iterable
    {
        yield 'bad' => 'not an argument array';
    }
}
