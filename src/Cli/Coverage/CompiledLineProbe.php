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
 * measure() writes a copy of each file with one stop statement in its header
 * line. The stop statement follows the opening tag and any declare and
 * namespace statements, and it adds no line.
 *
 * A subprocess starts the driver and includes each copy. PHP compiles the
 * complete copy, and the driver records its executable lines. Then the stop
 * statement returns before any code of the file executes. Thus, the result
 * contains the same lines as driver output for a loaded file. The stop
 * statement is the only executed code, so measure() removes each executed
 * line.
 *
 * A file with a syntax error or without an opening tag is not measured. If
 * the subprocess stops before it reports, measure() divides the batch and
 * tries again. If one file alone stops the subprocess, measure() throws an
 * error that names the file.
 *
 * @internal
 */
final readonly class CompiledLineProbe implements ExecutableLineProbe
{
    private const int BATCH_SIZE = 200;

    private const string MARKER = '#greenlight-line-probe#';

    private const int DRIVER_UNAVAILABLE = 4;

    private const string STOP = ' if (\GREENLIGHT_LINE_PROBE_STOP) { return; }';

    private const string SCRIPT = <<<'PHP'
        \define('GREENLIGHT_LINE_PROBE_STOP', true);
        $files = \explode("\0", (string) \file_get_contents($argv[1]));
        $index = \array_flip($files);
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
            \ob_start();
            try {
                include $file;
            } catch (\Throwable) {
            }
            \ob_end_clean();
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
     * Inserts the stop statement in the header line of a PHP source.
     *
     * Returns null for a source without an opening tag.
     */
    public static function stopped(string $source): ?string
    {
        $tokens = \array_values(\PhpToken::tokenize($source));
        $count = \count($tokens);
        $open = null;

        foreach ($tokens as $index => $token) {
            if ($token->is(\T_OPEN_TAG)) {
                $open = $index;

                break;
            }

            if ($token->is(\T_OPEN_TAG_WITH_ECHO)) {
                return null;
            }
        }

        if ($open === null) {
            return null;
        }

        $insert = $tokens[$open]->pos + \strlen(\rtrim($tokens[$open]->text, "\r\n"));
        $i = self::significant($tokens, $open + 1);

        while ($i < $count && $tokens[$i]->is(\T_DECLARE)) {
            $close = self::closingParenthesis($tokens, $i);

            if ($close === null) {
                return null;
            }

            $end = self::significant($tokens, $close + 1);

            if ($end >= $count || $tokens[$end]->text !== ';') {
                break;
            }

            $insert = $tokens[$end]->pos + 1;
            $i = self::significant($tokens, $end + 1);
        }

        if ($i < $count && $tokens[$i]->is(\T_NAMESPACE)) {
            $next = self::significant($tokens, $i + 1);

            if ($next < $count && $tokens[$next]->is([\T_STRING, \T_NAME_QUALIFIED])) {
                $next = self::significant($tokens, $next + 1);
            }

            if ($next < $count && ($tokens[$next]->text === ';' || $tokens[$next]->text === '{')) {
                $insert = $tokens[$next]->pos + 1;
            }
        }

        return \substr_replace($source, self::STOP, $insert, 0);
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function significant(array $tokens, int $from): int
    {
        $count = \count($tokens);

        while ($from < $count && $tokens[$from]->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
            ++$from;
        }

        return $from;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function closingParenthesis(array $tokens, int $from): ?int
    {
        $depth = 0;
        $count = \count($tokens);

        for ($i = $from; $i < $count; ++$i) {
            if ($tokens[$i]->text === '(') {
                ++$depth;
            } elseif ($tokens[$i]->text === ')') {
                --$depth;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
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
        $directory = $this->temporaryDirectory();
        $list = $directory . '/files';
        $errors = $directory . '/errors';
        $copies = [];

        try {
            foreach ($files as $index => $file) {
                $source = ErrorTrap::run(static fn() => \file_get_contents($file));
                $stopped = \is_string($source) ? self::stopped($source) : null;

                if ($stopped === null) {
                    continue;
                }

                $copy = $directory . '/' . $index . '.php';

                if (ErrorTrap::run(static fn() => \file_put_contents($copy, $stopped)) === false) {
                    throw CoverageError::lineProbeFailed(\sprintf('Greenlight could not write a copy of "%s".', $file));
                }

                $copies[$index] = $copy;
            }

            if ($copies === []) {
                return [];
            }

            if (ErrorTrap::run(static fn() => \file_put_contents($list, \implode("\0", $copies))) === false) {
                throw CoverageError::lineProbeFailed('Greenlight could not write the file list.');
            }

            [$exitCode, $output] = $this->execute($directory, $list, $errors);
            $stderr = \trim((string) ErrorTrap::run(static fn() => \file_get_contents($errors)));
        } finally {
            foreach ([...\array_values($copies), $list, $errors] as $path) {
                ErrorTrap::run(static fn() => \unlink($path));
            }

            ErrorTrap::run(static fn() => \rmdir($directory));
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

        $sources = \array_keys($copies);
        $byPath = [];

        foreach ($decoded as $key => $statuses) {
            if (\is_int($key) && isset($sources[$key])) {
                $byPath[$files[$sources[$key]]] = $statuses;
            }
        }

        $lines = [];

        foreach (new RawCoverage($byPath)->lines as $file => $statuses) {
            // Only the stop statement executes, so an executed line is not a line of the file.
            $lines[$file] = \array_filter($statuses, static fn(int $status): bool => $status < 1);
        }

        return $lines;
    }

    /**
     * @return array{int, string}
     *
     * @throws CoverageError
     */
    private function execute(string $directory, string $list, string $errors): array
    {
        $settings = ['display_errors=stderr', 'auto_prepend_file=', 'auto_append_file='];

        if ($this->driver === 'xdebug') {
            $settings[] = 'xdebug.mode=coverage';
        } else {
            $settings[] = 'pcov.enabled=1';
            $settings[] = 'pcov.directory=' . $directory;
            $settings[] = 'pcov.exclude=';
        }

        $command = [$this->phpBinary];

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
     * @return non-empty-string
     *
     * @throws CoverageError
     */
    private function temporaryDirectory(): string
    {
        $directory = \rtrim($this->temporaryDirectory === '' ? \sys_get_temp_dir() : $this->temporaryDirectory, '/')
            . '/greenlight-line-probe-' . \bin2hex(\random_bytes(6));

        if (!ErrorTrap::run(static fn() => \mkdir($directory, 0o700, true), $warning)) {
            throw CoverageError::lineProbeFailed(\sprintf(
                'Greenlight could not create the directory "%s"%s.',
                $directory,
                $warning === null ? '' : ': ' . $warning,
            ));
        }

        $real = ErrorTrap::run(static fn() => \realpath($directory));

        return \is_string($real) && $real !== '' ? $real : $directory;
    }
}
