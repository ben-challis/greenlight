<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Collection;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Collection\ExecutableLineProbe;
use Greenlight\Coverage\Collection\UnloadedFileCoverage;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class UnloadedFileCoverageTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function anUntestedFileDecreasesTheTotal(): void
    {
        $root = $this->sourceTree();
        $probe = new RecordingLineProbe();
        $map = new CoverageMap([new FileCoverage($root . '/Tested.php', [3, 4], [])]);

        $completed = new UnloadedFileCoverage($probe)->complete($map, [$root]);

        expect($probe->measured)
            ->because('the probe MUST measure only PHP files that the map does not contain')
            ->toBe([[$root . '/nested/Untested.php']]);
        expect($completed->totalPercentage())
            ->because('an untested file MUST decrease the total percentage')
            ->toBe(50.0);
        expect($completed->uncoveredLineTotal())->toBe(2);
    }

    #[Test]
    public function aCompleteMapDoesNotStartTheProbe(): void
    {
        $root = $this->sourceTree();
        $probe = new RecordingLineProbe();
        $map = new CoverageMap([
            new FileCoverage($root . '/Tested.php', [3], []),
            new FileCoverage($root . '/nested/Untested.php', [3], []),
        ]);

        $completed = new UnloadedFileCoverage($probe)->complete($map, [$root]);

        expect($probe->measured)->because('a complete map MUST NOT start the probe')->toBe([]);
        expect($completed)->toBe($map);
    }

    #[Test]
    public function noIncludePathAddsNoFile(): void
    {
        $probe = new RecordingLineProbe();
        $map = CoverageMap::empty();

        expect(new UnloadedFileCoverage($probe)->complete($map, []))
            ->because('without include paths the scan has no scope')
            ->toBe($map);
        expect($probe->measured)->toBe([]);
    }

    /**
     * @return non-empty-string
     */
    private function sourceTree(): string
    {
        $root = \realpath($this->temporaryDirectory->path());
        \assert(\is_string($root));
        \mkdir($root . '/nested');
        \file_put_contents($root . '/Tested.php', "<?php\n\nreturn 1;\n");
        \file_put_contents($root . '/nested/Untested.php', "<?php\n\nreturn 2;\n");
        \file_put_contents($root . '/nested/notes.txt', "not PHP\n");

        return $root;
    }
}

/**
 * @internal
 */
final class RecordingLineProbe implements ExecutableLineProbe
{
    /** @var list<list<non-empty-string>> */
    public array $measured = [];

    #[\Override]
    public function measure(array $files): CoverageMap
    {
        $this->measured[] = $files;

        return new CoverageMap(\array_map(static fn(string $file): FileCoverage => new FileCoverage($file, [], [3, 4]), $files));
    }
}
