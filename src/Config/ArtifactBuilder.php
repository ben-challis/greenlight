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

    /** @var positive-int|null */
    private ?int $maxCompletedRuns = null;

    /** @var positive-int|null */
    private ?int $maxCompletedRunAgeSeconds = null;

    /** @var positive-int|null */
    private ?int $maxRetainedBytes = null;

    /**
     * @param non-empty-string $directory
     *
     * @throws InvalidConfiguration
     */
    public function directory(string $directory): self
    {
        if ($directory === '') {
            throw InvalidConfiguration::emptyArtifactDirectory();
        }

        if (\str_contains($directory, "\0")) {
            throw InvalidConfiguration::artifactDirectoryContainsNullByte();
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
            throw InvalidConfiguration::invalidArtifactCountPerTest();
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
            throw InvalidConfiguration::invalidArtifactCountPerRun();
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
     * @param positive-int $count
     *
     * @throws InvalidConfiguration
     */
    public function maxCompletedRuns(int $count): self
    {
        if ($count < 1) {
            throw InvalidConfiguration::invalidCompletedRunCount();
        }

        $this->maxCompletedRuns = $count;

        return $this;
    }

    /**
     * @param positive-int $seconds
     *
     * @throws InvalidConfiguration
     */
    public function maxCompletedRunAge(int $seconds): self
    {
        if ($seconds < 1) {
            throw InvalidConfiguration::invalidCompletedRunAge();
        }

        $this->maxCompletedRunAgeSeconds = $seconds;

        return $this;
    }

    /**
     * @param non-empty-string $size
     *
     * @throws InvalidConfiguration
     */
    public function maxRetainedSize(string $size): self
    {
        $this->maxRetainedBytes = MemorySize::parseToBytes($size);

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
            $this->maxCompletedRuns,
            $this->maxCompletedRunAgeSeconds,
            $this->maxRetainedBytes,
        );
    }
}
