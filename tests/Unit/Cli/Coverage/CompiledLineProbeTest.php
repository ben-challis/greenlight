<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Coverage\CompiledLineProbe;
use Greenlight\Condition\ExtensionLoaded;
use Greenlight\Coverage\Collection\RawCoverage;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class CompiledLineProbeTest
{
    private const string LOAD_WITH_XDEBUG = <<<'PHP'
        \xdebug_start_code_coverage(\XDEBUG_CC_UNUSED | \XDEBUG_CC_DEAD_CODE);
        require $argv[1];
        echo \json_encode(\xdebug_get_code_coverage());
        PHP;

    private const string LOAD_WITH_PCOV = <<<'PHP'
        \pcov\start();
        require $argv[1];
        \pcov\stop();
        echo \json_encode(\pcov\collect());
        PHP;

    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    #[SkipUnless(ExtensionLoaded::class, 'xdebug')]
    public function xdebugProbeLinesMatchTheLinesOfALoadedFile(): void
    {
        $this->assertProbeMatchesLoadedFile('xdebug', ['-d', 'xdebug.mode=coverage', '-r', self::LOAD_WITH_XDEBUG]);
    }

    #[Test]
    #[SkipUnless(ExtensionLoaded::class, 'pcov')]
    public function pcovProbeLinesMatchTheLinesOfALoadedFile(): void
    {
        $file = $this->sample();

        $this->assertProbeMatchesLoadedFile(
            'pcov',
            ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . \dirname($file), '-r', self::LOAD_WITH_PCOV],
        );
    }

    #[Test]
    #[SkipUnless(ExtensionLoaded::class, 'xdebug')]
    public function aFileThatDoesNotCompileIsNotMeasured(): void
    {
        $broken = $this->temporaryDirectory->path() . '/Broken.php';
        \file_put_contents($broken, "<?php\nfunction broken( {\n");
        $real = \realpath($broken);
        \assert(\is_string($real));

        $map = new CompiledLineProbe('xdebug', $this->temporaryDirectory->path())->measure([$real, $this->sample()]);

        expect(\array_keys($map->files()))
            ->because('the probe MUST skip a broken file and measure the other files')
            ->toBe([$this->sample()]);
    }

    #[Test]
    #[DataSet('headers')]
    public function theStopStatementGoesInTheHeaderLine(string $source, string $expected): void
    {
        expect(CompiledLineProbe::stopped($source))
            ->because('the stop statement MUST follow the header and MUST NOT add a line')
            ->toBe($expected);
    }

    /** @return iterable<string, array{string, string}> */
    public static function headers(): iterable
    {
        $stop = ' if (\\GREENLIGHT_LINE_PROBE_STOP) { return; }';

        yield 'open tag only' => ["<?php\nfinal class A {}\n", "<?php{$stop}\nfinal class A {}\n"];
        yield 'strict types' => ["<?php\n\ndeclare(strict_types=1);\n\nfinal class A {}\n", "<?php\n\ndeclare(strict_types=1);{$stop}\n\nfinal class A {}\n"];
        yield 'strict types and namespace' => ["<?php\ndeclare(strict_types=1);\nnamespace App\\Model;\nuse Foo;\n", "<?php\ndeclare(strict_types=1);\nnamespace App\\Model;{$stop}\nuse Foo;\n"];
        yield 'braced namespace' => ["<?php\nnamespace App {\n    function a() {}\n}\n", "<?php\nnamespace App {{$stop}\n    function a() {}\n}\n"];
        yield 'global braced namespace' => ["<?php\nnamespace {\n}\n", "<?php\nnamespace {{$stop}\n}\n"];
    }

    #[Test]
    public function aSourceWithoutAnOpeningTagIsNotMeasured(): void
    {
        expect(CompiledLineProbe::stopped("plain text\n"))->toBeNull();
        expect(CompiledLineProbe::stopped("<?= 'template' ?>\n"))->toBeNull();
    }

    #[Test]
    public function aFileThatStopsTheSubprocessFailsTheProbe(): void
    {
        $failing = $this->temporaryDirectory->path() . '/failing-php';
        \file_put_contents($failing, "#!/bin/sh\necho 'probe stopped' >&2\nexit 70\n");
        \chmod($failing, 0o755);

        expect()->calling(fn(): CoverageMap => new CompiledLineProbe('xdebug', $this->temporaryDirectory->path(), $failing)->measure([$this->sample()]))
            ->because('a file that the probe cannot measure MUST NOT disappear from the report without a message')
            ->toThrow(
                CoverageError::class,
                message: \sprintf(
                    'Greenlight could not measure the source files that no test loaded. The subprocess stopped while it compiled "%s": exit code 70, probe stopped',
                    $this->sample(),
                ),
            );
    }

    /**
     * @param 'pcov'|'xdebug' $driver
     * @param list<string> $arguments
     */
    private function assertProbeMatchesLoadedFile(string $driver, array $arguments): void
    {
        $file = $this->sample();
        $loaded = $this->loadedCoverage($arguments, $file)->files()[$file] ?? null;
        $probed = new CompiledLineProbe($driver, $this->temporaryDirectory->path())->measure([$file])->files()[$file] ?? null;

        expect($loaded)->because('the driver MUST report the loaded sample file')->not()->toBeNull();
        expect($probed)->because('the probe MUST report the sample file')->not()->toBeNull();
        \assert($loaded instanceof FileCoverage && $probed instanceof FileCoverage);

        $executable = [...$loaded->coveredLines, ...$loaded->uncoveredLines];
        \sort($executable);

        expect($probed->uncoveredLines)
            ->because('the probe MUST report the same executable lines as the driver for a loaded file')
            ->toBe($executable);
        expect($probed->coveredLines)
            ->because('the probe MUST NOT execute the file')
            ->toBe([]);
    }

    /**
     * @param list<string> $arguments
     */
    private function loadedCoverage(array $arguments, string $file): CoverageMap
    {
        $command = [\PHP_BINARY, ...$arguments, '--', $file];
        $process = \proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [...\getenv(), 'XDEBUG_MODE' => 'coverage']);
        \assert(\is_resource($process));
        $output = (string) \stream_get_contents($pipes[1]);
        \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($process);

        $decoded = \json_decode(\substr($output, (int) \strpos($output, '{')), true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('The driver subprocess did not print coverage JSON: ' . $output);
        }

        return new RawCoverage($decoded)->toMap();
    }

    /**
     * @return non-empty-string
     */
    private function sample(): string
    {
        $file = \realpath(\dirname(__DIR__, 3) . '/Fixture/CoverageProbe/ProbeSample.php');
        \assert(\is_string($file));

        return $file;
    }
}
