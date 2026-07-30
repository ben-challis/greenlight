<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactSession;

final readonly class ArtifactSessionTest
{
    #[Test]
    #[DataSet('validDirectories')]
    public function artifactSessionsRetainZeroDirectories(
        string $stagingDirectory,
        string $publicDirectory,
    ): void {
        $session = new ArtifactSession($stagingDirectory, $publicDirectory);
        $decoded = ArtifactSession::fromWire($session->toWire());

        Expect::that([$session->stagingDirectory, $session->publicDirectory])
            ->because('an artifact session MUST retain each non-empty storage directory')
            ->toBe([$stagingDirectory, $publicDirectory])
            ->and([$decoded->stagingDirectory, $decoded->publicDirectory])
            ->because('artifact storage directories MUST survive the wire')
            ->toBe([$stagingDirectory, $publicDirectory]);
    }

    #[Test]
    #[DataSet('invalidDirectories')]
    public function artifactSessionsRejectMissingDirectories(
        string $stagingDirectory,
        string $publicDirectory,
        string $message,
    ): void {
        Expect::that(static fn(): ArtifactSession => new ArtifactSession(
            $stagingDirectory,
            $publicDirectory,
        ))
            ->because('an artifact session MUST identify both storage directories')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidDirectories(): iterable
    {
        yield 'empty staging directory' => [
            '',
            '/project/build/artifacts/run-1',
            'Artifact staging directory must not be empty.',
        ];
        yield 'empty public directory' => [
            '/project/.greenlight/staging/run-1',
            '',
            'Artifact public directory must not be empty.',
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validDirectories(): iterable
    {
        yield 'zero staging directory' => ['0', '/project/build/artifacts/run-1'];
        yield 'zero public directory' => ['/project/.greenlight/staging/run-1', '0'];
    }
}
