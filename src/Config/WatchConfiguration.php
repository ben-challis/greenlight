<?php

declare(strict_types=1);

namespace Greenlight\Config;

/** @internal */
final readonly class WatchConfiguration
{
    /**
     * @var positive-int
     */
    public int $debounceMilliseconds;

    /**
     * @throws InvalidConfiguration
     */
    public function __construct(int $debounceMilliseconds = 200)
    {
        if ($debounceMilliseconds < 1) {
            throw new InvalidConfiguration(\sprintf(
                'The watch debounce must be at least 1 millisecond, got %d.',
                $debounceMilliseconds,
            ));
        }

        $this->debounceMilliseconds = $debounceMilliseconds;
    }
}
