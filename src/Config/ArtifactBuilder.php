<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Collects the configuration for attachment output and safety limits.
 */
final class ArtifactBuilder
{
    private string $directory = ArtifactConfiguration::DEFAULT_DIRECTORY;
    private int $maxAttachmentsPerTest = ArtifactConfiguration::DEFAULT_MAX_ATTACHMENTS_PER_TEST;
    private int $maxAttachmentBytes = ArtifactConfiguration::DEFAULT_MAX_ATTACHMENT_BYTES;
    private int $maxTestBytes = ArtifactConfiguration::DEFAULT_MAX_TEST_BYTES;
    private int $maxRunAttachments = ArtifactConfiguration::DEFAULT_MAX_RUN_ATTACHMENTS;
    private int $maxRunBytes = ArtifactConfiguration::DEFAULT_MAX_RUN_BYTES;

    public function directory(string $directory): self
    {
        if ($directory === '') {
            throw new InvalidConfiguration('Artifact directory cannot be empty.');
        }

        $this->directory = $directory;

        return $this;
    }

    public function maxAttachmentsPerTest(int $count): self
    {
        if ($count < 1) {
            throw new InvalidConfiguration('Artifact count per test must be at least 1.');
        }

        $this->maxAttachmentsPerTest = $count;

        return $this;
    }

    public function maxAttachmentSize(string $size): self
    {
        $this->maxAttachmentBytes = MemorySize::parseToBytes($size);

        return $this;
    }

    public function maxTestSize(string $size): self
    {
        $this->maxTestBytes = MemorySize::parseToBytes($size);

        return $this;
    }

    public function maxRunAttachments(int $count): self
    {
        if ($count < 1) {
            throw new InvalidConfiguration('Artifact count per run must be at least 1.');
        }

        $this->maxRunAttachments = $count;

        return $this;
    }

    public function maxRunSize(string $size): self
    {
        $this->maxRunBytes = MemorySize::parseToBytes($size);

        return $this;
    }

    public function toConfiguration(): ArtifactConfiguration
    {
        return new ArtifactConfiguration(
            $this->directory,
            $this->maxAttachmentsPerTest,
            $this->maxAttachmentBytes,
            $this->maxTestBytes,
            $this->maxRunAttachments,
            $this->maxRunBytes,
        );
    }
}
