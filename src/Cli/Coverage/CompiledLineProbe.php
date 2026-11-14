<?php

declare(strict_types=1);

namespace Greenlight\Cli\Coverage;

use Greenlight\Coverage\Collection\RawCoverage;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Compiles source files in a PHP subprocess while the coverage driver runs.
 *
 * measure() starts one subprocess for each batch of files. The subprocess
 * starts the driver and compiles each file with opcache_compile_file(). The
 * driver reports the executable lines of each compiled file. Thus, the
 * result contains the same lines as driver output for a loaded file. The
 * subprocess does not execute the files.
 *
 * A file that does not compile is not measured. If the subprocess stops
 * before it reports, measure() divides the batch and tries again. If one file
 * alone stops the subprocess, measure() throws an error that names the file.
 *
 * @internal
 */
final readonly class CompiledLineProbe implements ExecutableLineProbe
{
    private const int BATCH_SIZE = 200;

    private const string MARKER = '#greenlight-line-probe#';

    private const int OPCACHE_UNAVAILABLE = 3;

    private const int DRIVER_UNAVAILABLE = 4;

    private const string SCRIPT = <<<'PHP'
        $files = \explode("\0", (string) \file_get_contents($argv[1]));
        $index = \array_flip($files);
        if (!\function_exists('opcache_compile_file') || !\is_array(\opcache_get_status(false))) {
            exit(3);
        }
        if ($argv[2] === 'xdebug') {
            if (!\function_exists('xdebug_start_code_coverage')) {
                exit(4);
            }
            \xdebug_start_code_coverage(\XDEBUG_CC_UNUSED | \XDEBUG_CC_DEAD_CODE);
        } else {
            if (!\function_exists('pcov\start')) {
                exit(4);
            }
            \pcov\start();
        }
        foreach ($files as $file) {
            try {
                \opcache_compile_file($file);
            } catch (\Throwable) {
            }
        }
        $lines = [];
        foreach ($argv[2] === 'xdebug' ? \xdebug_get_code_coverage() : \pcov\collect() as $path => $statuses) {
            if (isset($index[$path])) {
                $lines[$index[$path]] = $statuses;
            }
        }
        echo '#greenlight-line-probe#', \json_encode((object) $lines);
        PHP;

    /**
     * @param 'pcov'|'xdebug' $driver
     */
    public function __construct(
        private string $driver,
        private string $temporaryDirectory,
        private string $phpBinary = \PHP_BINARY,
    ) {}

    #[\Override]
    public function measure(array $files): CoverageMap
    {
        $lines = [];

        foreach (\array_chunk($files, self::BATCH_SIZE) as $batch) {
            $lines = [...$lines, ...$this->measureBatch($batch)];
        }

        return new RawCoverage($lines)->toMap();
    }

    /**
     * @param list<non-empty-string> $files
     *
     * @return array<string, array<int, int>>
     *
     * @throws CoverageError
     */
    private function measureBatch(array $files): array
    {
        if ($files === []) {
            return [];
        }

        $measured = $this->run($files, $failure);

        if ($measured !== null) {
            return $measured;
        }

        if (\count($files) === 1) {
            throw CoverageError::lineProbeFailed(\sprintf('The subprocess stopped while it compiled "%s": %s', $files[0], $failure));
        }

        $half = \intdiv(\count($files), 2);

        return [
            ...$this->measureBatch(\array_slice($files, 0, $half)),
            ...$this->measureBatch(\array_slice($files, $half)),
        ];
    }

    /**
     * @param non-empty-list<non-empty-string> $files
     *
     * @param-out string $failure the reason when the result is null
     *
     * @return array<string, array<int, int>>|null null when the subprocess stops before it reports
     *
     * @throws CoverageError
     */
    private function run(array $files, ?string &$failure = null): ?array
    {
        $failure = '';
        $list = $this->temporaryFile('list');
        $errors = $this->temporaryFile('errors');

        try {
            if (ErrorTrap::run(static fn() => \file_put_contents($list, \implode("\0", $files))) === false) {
                throw CoverageError::lineProbeFailed('Greenlight could not write the file list.');
            }

            [$exitCode, $output] = $this->execute($files, $list, $errors);
            $stderr = \trim((string) ErrorTrap::run(static fn() => \file_get_contents($errors)));
        } finally {
            ErrorTrap::run(static fn() => \unlink($list));
            ErrorTrap::run(static fn() => \unlink($errors));
        }

        if ($exitCode === self::OPCACHE_UNAVAILABLE) {
            throw CoverageError::lineProbeFailed(
                'The subprocess cannot use OPcache. Install the Zend OPcache extension, or load it with zend_extension=opcache.',
            );
        }

        if ($exitCode === self::DRIVER_UNAVAILABLE) {
            throw CoverageError::lineProbeFailed(\sprintf(
                'The subprocess cannot start the "%s" coverage driver.%s',
                $this->driver,
                $stderr === '' ? '' : ' ' . $stderr,
            ));
        }

        $position = \strrpos($output, self::MARKER);
        $decoded = $position === false ? null : \json_decode(\substr($output, $position + \strlen(self::MARKER)), true);

        if ($exitCode !== 0 || !\is_array($decoded)) {
            $failure = \sprintf('exit code %d%s', $exitCode, $stderr === '' ? '' : ', ' . $stderr);

            return null;
        }

        $byPath = [];

        foreach ($decoded as $key => $statuses) {
            if (\is_int($key) && isset($files[$key])) {
                $byPath[$files[$key]] = $statuses;
            }
        }

        $lines = [];

        foreach (new RawCoverage($byPath)->lines as $file => $statuses) {
            // The subprocess executes no file code, so each line that is not dead code is uncovered.
            $lines[$file] = \array_map(static fn(int $status): int => $status === -2 ? -2 : -1, $statuses);
        }

        return $lines;
    }

    /**
     * @param non-empty-list<non-empty-string> $files
     *
     * @return array{int, string}
     *
     * @throws CoverageError
     */
    private function execute(array $files, string $list, string $errors): array
    {
        $command = [$this->phpBinary];

        if (!\extension_loaded('Zend OPcache')) {
            $command[] = '-d';
            $command[] = 'zend_extension=opcache';
        }

        $settings = [
            'display_errors=stderr',
            'auto_prepend_file=',
            'auto_append_file=',
            'opcache.enable=1',
            'opcache.enable_cli=1',
            'opcache.optimization_level=0',
            'opcache.jit=disable',
            'opcache.file_cache=',
            'opcache.file_cache_only=0',
            'opcache.preload=',
            'opcache.restrict_api=',
        ];

        if ($this->driver === 'xdebug') {
            $settings[] = 'xdebug.mode=coverage';
        } else {
            $settings[] = 'pcov.enabled=1';
            $settings[] = 'pcov.directory=' . $this->commonDirectory($files);
        }

        foreach ($settings as $setting) {
            $command[] = '-d';
            $command[] = $setting;
        }
        $command[] = '-r';
        $command[] = self::SCRIPT;
        $command[] = '--';
        $command[] = $list;
        $command[] = $this->driver;

        $environment = \getenv();

        if ($this->driver === 'xdebug') {
            $environment['XDEBUG_MODE'] = 'coverage';
        }

        $pipes = [];
        $process = ErrorTrap::run(static function () use ($command, $errors, $environment, &$pipes): mixed {
            return \proc_open(
                $command,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']],
                $pipes,
                null,
                $environment,
                ['bypass_shell' => true],
            );
        }, $warning);

        if (!\is_resource($process) || !isset($pipes[0], $pipes[1])) {
            throw CoverageError::lineProbeFailed($warning ?? 'Greenlight could not start the PHP subprocess.');
        }

        \fclose($pipes[0]);
        $output = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);

        return [\proc_close($process), $output];
    }

    /**
     * @param non-empty-list<non-empty-string> $files
     */
    private function commonDirectory(array $files): string
    {
        $common = \explode('/', \dirname($files[0]));

        foreach ($files as $file) {
            $parts = \explode('/', \dirname($file));
            $length = 0;

            while ($length < \count($common) && $length < \count($parts) && $common[$length] === $parts[$length]) {
                ++$length;
            }

            $common = \array_slice($common, 0, $length);
        }

        $directory = \implode('/', $common);

        return $directory === '' ? '/' : $directory;
    }

    /**
     * @return non-empty-string
     *
     * @throws CoverageError
     */
    private function temporaryFile(string $purpose): string
    {
        $file = ErrorTrap::run(fn() => \tempnam($this->temporaryDirectory, 'greenlight-line-probe-' . $purpose . '-'), $warning);

        if (!\is_string($file) || $file === '') {
            throw CoverageError::lineProbeFailed(\sprintf(
                'Greenlight could not create a temporary file in "%s"%s.',
                $this->temporaryDirectory,
                $warning === null ? '' : ': ' . $warning,
            ));
        }

        return $file;
    }
}
