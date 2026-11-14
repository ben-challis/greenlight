<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Coverage\CoverageGate;
use Greenlight\Cli\Coverage\CoverageSession;
use Greenlight\Cli\Coverage\ExecutableLineProbe;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class CoverageSessionUnloadedFileTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function anUntestedClassFailsAFullCoverageGate(): void
    {
        $root = \realpath($this->temporaryDirectory->path());
        \assert(\is_string($root));
        \mkdir($root . '/src');
        \mkdir($root . '/tmp');
        \file_put_contents($root . '/src/Tested.php', "<?php\n\nreturn 1;\n");
        \file_put_contents($root . '/src/Untested.php', "<?php\n\nreturn 2;\n");

        $probe = new class implements ExecutableLineProbe {
            #[\Override]
            public function measure(array $files): CoverageMap
            {
                return new CoverageMap(\array_map(static fn(string $file): FileCoverage => new FileCoverage($file, [], [3]), $files));
            }
        };
        $session = CoverageSession::open(new CoverageSettings([$root . '/src']), false, $root . '/tmp', $probe);

        $coverage = $session->finish(new CoverageMap([new FileCoverage($root . '/src/Tested.php', [3], [])]));
        $session->close();
        \assert($coverage instanceof CoverageMap);

        expect(\array_keys($coverage->files()))
            ->because('the session MUST add the source file that no test loaded')
            ->toBe([$root . '/src/Tested.php', $root . '/src/Untested.php']);
        expect(CoverageGate::failures(new CoverageConfiguration([], null, [], 100.0), $coverage))
            ->because('an untested class MUST fail a 100 percent gate')
            ->toBe(['Coverage gate failed: 50.00% is less than the minimum 100.00%.']);
    }
}
