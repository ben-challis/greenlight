<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

/**
 * Tracks the attachment budget of one test across all test attempts.
 *
 * @internal
 */
final class TestArtifactBudget
{
    public int $attachments = 0;
    public int $bytes = 0;
}
