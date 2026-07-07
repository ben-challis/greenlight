<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

/**
 * The parallel slot a test is running in, for deriving isolated external
 * resources.
 *
 * Channels are small integers from 1 to the worker count. Each live worker
 * holds exactly one channel for its lifetime, and when a worker is recycled
 * or crashes its replacement reuses the freed number. Two tests running at
 * the same time therefore never share a channel, which makes the number safe
 * to embed in database names, ports, virtual hosts, or temp directories. The
 * in-process runner (workers=1) is always channel 1.
 *
 * Channel-derived resources are per slot, not per worker process: a
 * replacement worker that reuses channel 2 sees whatever state the previous
 * channel-2 worker left behind. That persistence is what makes patterns like
 * one database schema per channel work across recycling.
 *
 * Inject this class through a test constructor, or resolve it in a harness
 * provider, to read the slot. The same value is exported to the worker
 * process as the GREENLIGHT_CHANNEL environment variable, so code outside
 * the harness (bootstrap files, spawned tools) can read it via getenv().
 *
 * number is the raw slot. label() prefixes it as "gl-<number>" for use in
 * resource names that need a recognisable, collision-free string.
 */
final readonly class TestChannel
{
    /**
     * @param positive-int $number
     */
    public function __construct(
        public int $number,
    ) {}

    /**
     * @return non-empty-string
     */
    public function label(): string
    {
        return 'gl-' . $this->number;
    }
}
