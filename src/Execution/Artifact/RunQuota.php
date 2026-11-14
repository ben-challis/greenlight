<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Internal\Text\DecimalInteger;

/**
 * Counts staged attachments and bytes for one run across all workers.
 *
 * The `.quota` file in the staging directory holds the count and the byte
 * total. The reserve() and release() methods update both values under an
 * exclusive file lock. The reserve() method rejects an attachment that exceeds
 * the run limits.
 *
 * @internal
 */
final readonly class RunQuota
{
    /** @param non-empty-string $stagingDirectory */
    public function __construct(
        private string $stagingDirectory,
        private ArtifactConfiguration $configuration,
    ) {}

    /** @throws AttachmentError */
    public function reserve(int $bytes): void
    {
        $this->update(function (int $count, int $used) use ($bytes): array {
            if ($count >= $this->configuration->maxRunAttachments) {
                throw AttachmentError::limit(\sprintf(
                    'This run has reached the limit of %d attachments',
                    $this->configuration->maxRunAttachments,
                ));
            }

            if ($bytes > $this->configuration->maxRunBytes
                || $used > $this->configuration->maxRunBytes - $bytes
            ) {
                throw AttachmentError::limit(\sprintf(
                    'Attachments for this run exceed the limit of %d bytes',
                    $this->configuration->maxRunBytes,
                ));
            }

            return [$count + 1, $used + $bytes];
        });
    }

    /** @throws AttachmentError */
    public function release(int $bytes): void
    {
        $this->update(static fn(int $count, int $used): array => [
            \max(0, $count - 1),
            \max(0, $used - $bytes),
        ]);
    }

    /**
     * @param \Closure(int, int): array{int, int} $update
     * @throws AttachmentError
     */
    private function update(\Closure $update): void
    {
        $path = $this->stagingDirectory . '/.quota';

        if (ErrorTrap::run(static fn() => \is_link($path))
            || (ErrorTrap::run(static fn() => \file_exists($path)) && !ErrorTrap::run(static fn() => \is_file($path)))
        ) {
            throw AttachmentError::storage('Attachment quota path is unsafe');
        }

        $stream = ErrorTrap::run(static fn() => \fopen($path, 'c+'), $warning);

        if ($stream === false) {
            throw AttachmentError::storage('Failed to lock the attachment quota' . ($warning === null ? '' : ': ' . $warning));
        }

        if (!ErrorTrap::run(static fn() => \flock($stream, \LOCK_EX), $warning)) {
            ErrorTrap::run(static fn() => \fclose($stream));

            throw AttachmentError::storage('Failed to lock the attachment quota' . ($warning === null ? '' : ': ' . $warning));
        }

        try {
            ErrorTrap::run(static fn() => \rewind($stream));
            $raw = ErrorTrap::run(static fn() => \stream_get_contents($stream));
            $raw = \is_string($raw) ? \trim($raw) : '';

            if ($raw !== '' && \preg_match('/^\d+\s+\d+$/', $raw) !== 1) {
                throw AttachmentError::storage('Attachment quota metadata is corrupt');
            }

            $parts = \preg_split('/\s+/', $raw === '' ? '0 0' : $raw);
            $count = DecimalInteger::parse($parts[0] ?? '');
            $bytes = DecimalInteger::parse($parts[1] ?? '');

            if ($count === null || $bytes === null) {
                throw AttachmentError::storage('Attachment quota metadata is corrupt');
            }

            [$count, $bytes] = $update($count, $bytes);
            $encoded = \sprintf('%020d %020d' . "\n", $count, $bytes);
            $updated = ErrorTrap::run(
                static fn(): bool => \rewind($stream)
                    && \fwrite($stream, $encoded) === \strlen($encoded)
                    && \ftruncate($stream, \strlen($encoded))
                    && \fflush($stream),
                $warning,
            );

            if (!$updated) {
                throw AttachmentError::storage('Failed to update the attachment quota' . ($warning === null ? '' : ': ' . $warning));
            }

            ErrorTrap::run(static fn() => \chmod($path, 0o600));
        } finally {
            ErrorTrap::run(static fn() => \flock($stream, \LOCK_UN));
            ErrorTrap::run(static fn() => \fclose($stream));
        }
    }
}
