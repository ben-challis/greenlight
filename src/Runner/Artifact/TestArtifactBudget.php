<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

/**
 * Tracks one test's attachment budget across all retry attempts.
 *
 * @internal
 */
final class TestArtifactBudget
{
    public int $attachments = 0;
    public int $bytes = 0;
}
