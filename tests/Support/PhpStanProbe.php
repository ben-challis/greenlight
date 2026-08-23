<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Sandbox\TemporaryDirectory;

final readonly class PhpStanProbe
{
    /**
     * @param list<string> $goodErrors
     * @param list<string> $errors
     */
    private function __construct(
        public int $exitCode,
        public bool $goodPassed,
        public array $goodErrors,
        public array $errors,
    ) {}

    /**
     * @throws \RuntimeException when PHPStan cannot run or return a valid file report
     */
    public static function analyze(
        TemporaryDirectory $workspace,
        string $goodSource,
        string $badSource,
        ?string $configFile = null,
    ): self {
        return self::analyzeBatch(
            $workspace,
            ['probe' => ['good' => $goodSource, 'bad' => $badSource]],
            $configFile,
        )['probe'];
    }

    /**
     * @param array<string, array{good: string, bad: string}> $cases
     *
     * @return array<string, self>
     *
     * @throws \RuntimeException when PHPStan cannot run or return a valid file report
     */
    public static function analyzeBatch(
        TemporaryDirectory $workspace,
        array $cases,
        ?string $configFile = null,
        string $name = 'phpstan-probe',
    ): array {
        if ($cases === []) {
            throw new \InvalidArgumentException('A PHPStan probe batch requires at least one case.');
        }

        $root = \dirname(__DIR__, 2);
        $configFile ??= FixturePath::get('PhpStanExtension/probe.neon');
        $files = ProjectFiles::create($workspace, $name);
        $probeDirectory = $files->directory;
        $probeConfigFile = $probeDirectory . '/ProbeConfig.neon';
        $caseFiles = [];
        $analyzedFiles = [];

        foreach ($cases as $caseName => $case) {
            $relativeDirectory = 'cases/' . self::caseDirectory($caseName);
            $goodFile = $files->path($relativeDirectory . '/GoodProbe.php');
            $badFile = $files->path($relativeDirectory . '/BadProbe.php');

            $files->write($relativeDirectory . '/GoodProbe.php', $case['good']);
            $files->write($relativeDirectory . '/BadProbe.php', $case['bad']);
            $caseFiles[$caseName] = ['good' => $goodFile, 'bad' => $badFile];
            $analyzedFiles[] = $goodFile;
            $analyzedFiles[] = $badFile;
        }

        $files->write('ProbeConfig.neon', \sprintf(
            "includes:\n    - %s\n\nparameters:\n    tmpDir: %s\n",
            self::neonString($configFile),
            self::neonString($probeDirectory . '/cache'),
        ));

        $result = Subprocess::run(
            $root,
            [
                \PHP_BINARY,
                $root . '/vendor/bin/phpstan',
                'analyse',
                '--no-progress',
                '--error-format=json',
                '-c',
                $probeConfigFile,
                ...$analyzedFiles,
            ],
            [
                'TEMP' => $probeDirectory,
                'TMP' => $probeDirectory,
                'TMPDIR' => $probeDirectory,
            ],
        );

        try {
            $report = \json_decode($result->stdout, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new \RuntimeException('PHPStan did not return a valid JSON report.', $failure->getCode(), previous: $failure);
        }

        if (!\is_array($report) || !\is_array($report['files'] ?? null)) {
            throw new \RuntimeException('PHPStan JSON report did not contain a file map.');
        }

        $reportedFiles = [];

        foreach ($report['files'] as $file => $fileReport) {
            if (\is_string($file)) {
                $reportedFiles[$file] = $fileReport;
            }
        }

        $probes = [];

        foreach ($caseFiles as $caseName => $case) {
            $goodErrors = self::fileMessages($reportedFiles, $case['good']);

            $probes[$caseName] = new self(
                $result->exitCode,
                $goodErrors === [],
                $goodErrors,
                self::fileMessages($reportedFiles, $case['bad']),
            );
        }

        return $probes;
    }

    public function messages(): string
    {
        return \implode("\n", $this->errors);
    }

    private static function neonString(string $value): string
    {
        return '"' . \str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * @param array<string, mixed> $reportedFiles
     *
     * @return list<string>
     */
    private static function fileMessages(array $reportedFiles, string $file): array
    {
        $fileReport = $reportedFiles[$file] ?? null;
        $reportedMessages = \is_array($fileReport) ? ($fileReport['messages'] ?? null) : null;
        $messages = [];

        if (!\is_array($reportedMessages)) {
            return $messages;
        }

        foreach ($reportedMessages as $message) {
            if (\is_array($message) && \is_string($message['message'] ?? null)) {
                $messages[] = $message['message'];
            }
        }

        return $messages;
    }

    private static function caseDirectory(int|string $caseName): string
    {
        if (!\is_string($caseName) || $caseName === '') {
            throw new \InvalidArgumentException('A probe case name must be a nonempty string.');
        }

        $slug = \strtolower((string) \preg_replace('/[^a-z0-9]+/i', '-', $caseName));
        $slug = \trim($slug, '-');

        return ($slug === '' ? 'case' : $slug) . '-' . \substr(\hash('sha256', $caseName), 0, 8);
    }
}
