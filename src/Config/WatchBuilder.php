<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Configures watch mode inside the greenlight.php configurator callable.
 */
final class WatchBuilder
{
    /**
     * @var positive-int
     */
    private int $debounceMilliseconds = 200;

    /**
     * The quiet period: a re-run fires only once no further change has
     * arrived for this long, so save bursts coalesce into one run.
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
     */
    public function toConfiguration(): WatchConfiguration
    {
        return new WatchConfiguration($this->debounceMilliseconds);
    }
}
