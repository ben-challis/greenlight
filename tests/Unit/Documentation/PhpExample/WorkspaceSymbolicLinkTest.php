<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Documentation\PhpExample;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Documentation\PhpExample\Workspace;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class WorkspaceSymbolicLinkTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    #[DataRow(['docs-php'], label: 'destination')]
    #[DataRow(['staging'], label: 'staging')]
    #[DataRow(['docs-php/nested'], label: 'nested')]
    public function publicationPreservesSymbolicLinkTargets(string $entry): void
    {
        $root = $this->temporaryDirectory->subdirectory('project');
        $target = $this->temporaryDirectory->subdirectory('target');
        $path = $root . '/build/' . ($entry === 'staging' ? 'docs-php.next-' . \getmypid() : $entry);
        \mkdir(\dirname($path), 0o777, true);
        \file_put_contents($target . '/sentinel.txt', 'keep');
        \symlink($target, $path);

        new Workspace()->publish($root, []);

        expect(\file_get_contents($target . '/sentinel.txt'))->toBe('keep');
        expect(\is_link($path))->toBeFalse();
        expect(\is_file($root . '/build/docs-php/manifest.json'))->toBeTrue();
    }

    #[Test]
    public function publicationReplacesABrokenDestinationLink(): void
    {
        $root = $this->temporaryDirectory->subdirectory('project');
        \mkdir($root . '/build');
        \symlink($root . '/missing', $root . '/build/docs-php');

        new Workspace()->publish($root, []);

        expect(\is_link($root . '/build/docs-php'))->toBeFalse();
        expect(\is_file($root . '/build/docs-php/manifest.json'))->toBeTrue();
    }
}
