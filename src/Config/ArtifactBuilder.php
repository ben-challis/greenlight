<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Collects the configuration for attachment output and safety limits.
 */
final class ArtifactBuilder
{
    /** @var non-empty-string */
    private string $directory = ArtifactConfiguration::DEFAULT_DIRECTORY;

    /** @var positive-int */
    private int $maxAttachmentsPerTest = ArtifactConfiguration::DEFAULT_MAX_ATTACHMENTS_PER_TEST;

    /** @var positive-int */
    private int $maxAttachmentBytes = ArtifactConfiguration::DEFAULT_MAX_ATTACHMENT_BYTES;

    /** @var positive-int */
    private int $maxTestBytes = ArtifactConfiguration::DEFAULT_MAX_TEST_BYTES;

    /** @var positive-int */
    private int $maxRunAttachments = ArtifactConfiguration::DEFAULT_MAX_RUN_ATTACHMENTS;

    /** @var positive-int */
    private int $maxRunBytes = ArtifactConfiguration::DEFAULT_MAX_RUN_BYTES;

    /**
     * @param non-empty-string $directory
     *
     * @throws InvalidConfiguration
     */
    public function directory(string $directory): self
    {
        if ($directory === '') {
            throw new InvalidConfiguration('Artifact directory cannot be empty.');
        }

        if (\str_contains($directory, "\0")) {
            throw new InvalidConfiguration('Artifact directory cannot contain a null byte.');
        }

        $this->directory = $directory;

        return $this;
    }

    /**
     * @param positive-int $count
     *
     * @throws InvalidConfiguration
     */
    public function maxAttachmentsPerTest(int $count): self
    {
        if ($count < 1) {
            throw new InvalidConfiguration('Artifact count per test must be at least 1.');
        }

        $this->maxAttachmentsPerTest = $count;

        return $this;
    }

    /**
     * @param non-empty-string $size
     *
     * @throws InvalidConfiguration
     */
    public function maxAttachmentSize(string $size): self
    {
        $this->maxAttachmentBytes = MemorySize::parseToBytes($size);

        return $this;
    }

    /**
     * @param non-empty-string $size
     *
     * @throws InvalidConfiguration
     */
    public function maxTestSize(string $size): self
    {
        $this->maxTestBytes = MemorySize::parseToBytes($size);

        return $this;
    }

    /**
     * @param positive-int $count
     *
     * @throws InvalidConfiguration
     */
    public function maxRunAttachments(int $count): self
    {
        if ($count < 1) {
            throw new InvalidConfiguration('Artifact count per run must be at least 1.');
        }

        $this->maxRunAttachments = $count;

        return $this;
    }

    /**
     * @param non-empty-string $size
     *
     * @throws InvalidConfiguration
     */
    public function maxRunSize(string $size): self
    {
        $this->maxRunBytes = MemorySize::parseToBytes($size);

        return $this;
    }

    /**
     * @internal
     */
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
