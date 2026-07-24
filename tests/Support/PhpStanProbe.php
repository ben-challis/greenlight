<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Fixture\TempDirectory;

/**
 * Analyses one passing and one failing source file with the shipped PHPStan
 * extension, exposing only the observations acceptance tests need.
 */
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
    public static function analyse(
        TempDirectory $workspace,
        string $goodSource,
        string $badSource,
    ): self {
        $root = \dirname(__DIR__, 2);
        $probeDirectory = $workspace->subdirectory('phpstan-probe');
        $goodFile = $probeDirectory . '/GoodProbe.php';
        $badFile = $probeDirectory . '/BadProbe.php';

        \file_put_contents($goodFile, $goodSource);
        \file_put_contents($badFile, $badSource);

        $result = ProcessRunner::run(
            $root,
            [
                \PHP_BINARY,
                $root . '/vendor/bin/phpstan',
                'analyse',
                '--no-progress',
                '--error-format=json',
                '-c',
                $root . '/tests/Fixture/PhpStanExtension/probe.neon',
                $goodFile,
                $badFile,
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
}
