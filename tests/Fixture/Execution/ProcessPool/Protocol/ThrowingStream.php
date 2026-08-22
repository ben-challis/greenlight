<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\ProcessPool\Protocol;

use Greenlight\Doubles\Fake;

final class ThrowingStream implements Fake
{
    public mixed $context;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        return true;
    }

    public function stream_read(int $count): never
    {
        throw new \RuntimeException('Fixture stream read failed.');
    }

    public function stream_write(string $data): never
    {
        throw new \RuntimeException('Fixture stream write failed.');
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_set_option(
        int $option,
        int $argumentOne,
        ?int $argumentTwo,
    ): bool {
        return true;
    }
}
