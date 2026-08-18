<?php

declare(strict_types=1);

namespace Greenlight\Config;

final class WatchBuilder
{
    /**
     * @var positive-int
     */
    private int $debounceMilliseconds = 200;

    /**
     * Sets the quiet period before a new run. The period restarts after each
     * change. Thus, multiple consecutive saves cause one run.
     *
     * @throws InvalidConfiguration
     */
    public function debounceMilliseconds(int $milliseconds): self
    {
        if ($milliseconds < 1) {
            throw new InvalidConfiguration(\sprintf('The watch debounce must be at least 1 millisecond, got %d.', $milliseconds));
        }

        $this->debounceMilliseconds = $milliseconds;

        return $this;
    }

    /**
     * @internal
     * @throws InvalidConfiguration
     */
    public function toConfiguration(): WatchConfiguration
    {
        return new WatchConfiguration($this->debounceMilliseconds);
    }
}
