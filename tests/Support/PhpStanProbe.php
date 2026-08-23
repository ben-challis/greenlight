<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Sandbox\TemporaryDirectory;

final readonly class PhpStanProbe
{
    /**
     * @param list<string> $errors
     */
    private function __construct(
        public int $exitCode,
        public bool $goodPassed,
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
        $root = \dirname(__DIR__, 2);
        $configFile ??= FixturePath::get('PhpStanExtension/probe.neon');
        $files = ProjectFiles::create($workspace, 'phpstan-probe');
        $probeDirectory = $files->directory;
        $goodFile = $probeDirectory . '/GoodProbe.php';
        $badFile = $probeDirectory . '/BadProbe.php';
        $probeConfigFile = $probeDirectory . '/ProbeConfig.neon';

        $files->write('GoodProbe.php', $goodSource);
        $files->write('BadProbe.php', $badSource);
        $files->write('ProbeConfig.neon', \sprintf(
            "includes:\n    - %s\n\nparameters:\n    tmpDir: %s\n",
            self::neonString($configFile),
            self::neonString($probeDirectory . '/cache'),
        ));

        $result = PhpSubprocess::run(
            $root,
            [
                $root . '/vendor/bin/phpstan',
                'analyse',
                '--no-progress',
                '--error-format=json',
                '-c',
                $probeConfigFile,
                $goodFile,
                $badFile,
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

        $files = $report['files'];
        $badReport = $files[$badFile] ?? null;
        $reportedMessages = \is_array($badReport) ? ($badReport['messages'] ?? null) : null;
        $errors = [];

        if (\is_array($reportedMessages)) {
            foreach ($reportedMessages as $message) {
                if (\is_array($message) && \is_string($message['message'] ?? null)) {
                    $errors[] = $message['message'];
                }
            }
        }

        return new self(
            $result->exitCode,
            !isset($files[$goodFile]),
            $errors,
        );
    }

    public function messages(): string
    {
        return \implode("\n", $this->errors);
    }

    private static function neonString(string $value): string
    {
        return '"' . \str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
