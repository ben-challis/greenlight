<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

class PrivateConstantDefault
{
    private const string MODE = 'fast';

    /** @var array<string, string> */
    private const array OPTIONS = ['mode' => 'fast'];

    public function mode(string $value = self::MODE): string
    {
        return $value;
    }

    /**
     * @param array<string, string> $value
     * @return array<string, string>
     */
    public function options(array $value = PrivateConstantDefault::OPTIONS): array
    {
        return $value;
    }
}
