<?php

declare(strict_types=1);

namespace Greenlight\InfectionAdapter;

/** Converts per-test coverage JSONL to the PHPUnit coverage XML that Infection reads. */
final class CoverageXmlWriter
{
    private const string NAMESPACE = 'https://schema.phpunit.de/coverage/1.0';

    public function write(string $artifact, string $targetDirectory): void
    {
        $input = \fopen($artifact, 'rb');

        if (!\is_resource($input)) {
            throw new \RuntimeException(\sprintf('Could not read Greenlight coverage map "%s".', $artifact));
        }

        $spoolDirectory = \rtrim(\sys_get_temp_dir(), '/') . '/greenlight-infection-' . \bin2hex(\random_bytes(8));

        if (!\mkdir($spoolDirectory, 0o777, true) && !\is_dir($spoolDirectory)) {
            \fclose($input);

            throw new \RuntimeException(\sprintf('Could not create Greenlight Infection spool "%s".', $spoolDirectory));
        }

        $root = null;
        $tests = [];
        $files = [];

        try {
            try {
                while (($line = \fgets($input)) !== false) {
                    $record = $this->decodeRecord($line);

                    if (($record['v'] ?? null) !== 1 || !\is_string($record['type'] ?? null)) {
                        throw new \RuntimeException('The Greenlight coverage map contains an unsupported record.');
                    }

                    if ($record['type'] === 'meta') {
                        $root = $this->requiredString($record, 'root');

                        continue;
                    }

                    if ($record['type'] === 'test') {
                        $test = $record['test'] ?? null;

                        if (!\is_int($test) || $test < 0) {
                            throw new \RuntimeException('The Greenlight coverage map contains an invalid test ordinal.');
                        }

                        $tests[$test] = $this->requiredString($record, 'renderedId');

                        continue;
                    }

                    if ($record['type'] !== 'coverage' && $record['type'] !== 'source') {
                        continue;
                    }

                    $file = $this->requiredString($record, 'file');
                    $fileState = $files[$file] ??= [
                        'href' => 'files/' . \hash('sha256', $file) . '.xml',
                        'spool' => $spoolDirectory . '/' . \hash('sha256', $file) . '.lines',
                        'executable' => 0,
                        'executed' => 0,
                    ];
                    $lines = $this->positiveLines($record);

                    if ($record['type'] === 'source') {
                        $covered = $record['covered'] ?? null;

                        if (!\is_bool($covered)) {
                            throw new \RuntimeException('The Greenlight source record has an invalid covered flag.');
                        }

                        $fileState['executable'] += \count($lines);

                        if ($covered) {
                            $fileState['executed'] += \count($lines);
                        }
                    } else {
                        $test = $record['test'] ?? null;

                        if (!\is_int($test) || !isset($tests[$test])) {
                            throw new \RuntimeException('The Greenlight coverage record references an unknown test.');
                        }

                        $fragments = '';

                        foreach ($lines as $coveredLine) {
                            $fragments .= \sprintf(
                                "      <line nr=\"%d\"><covered by=\"%s\"/></line>\n",
                                $coveredLine,
                                $this->escape($tests[$test]),
                            );
                        }

                        if (\file_put_contents($fileState['spool'], $fragments, \FILE_APPEND | \LOCK_EX) === false) {
                            throw new \RuntimeException(\sprintf('Could not write Infection coverage spool for "%s".', $file));
                        }
                    }

                    $files[$file] = $fileState;
                }
            } finally {
                \fclose($input);
            }
        } catch (\Throwable $error) {
            $this->removeDirectory($spoolDirectory);

            throw $error;
        }

        if ($root === null) {
            $this->removeDirectory($spoolDirectory);

            throw new \RuntimeException('The Greenlight coverage map has no metadata record.');
        }

        if (!\is_dir($targetDirectory) && !\mkdir($targetDirectory, 0o777, true) && !\is_dir($targetDirectory)) {
            $this->removeDirectory($spoolDirectory);

            throw new \RuntimeException(\sprintf('Could not create Infection coverage directory "%s".', $targetDirectory));
        }

        try {
            $indexFiles = '';
            $totalExecutable = 0;
            $totalExecuted = 0;

            foreach ($files as $file => $state) {
                $relative = $this->relativeSourcePath($file, $root);
                $directory = \dirname($relative);
                $directory = $directory === '.' ? '' : $directory;
                $executable = $state['executable'];
                $executed = $state['executed'];
                $percentage = $executable === 0 ? 0.0 : $executed / $executable * 100;
                $coverageFile = \rtrim($targetDirectory, '/') . '/' . $state['href'];

                if (!\is_dir(\dirname($coverageFile))
                    && !\mkdir(\dirname($coverageFile), 0o777, true)
                    && !\is_dir(\dirname($coverageFile))
                ) {
                    throw new \RuntimeException(\sprintf('Could not create Infection coverage directory "%s".', \dirname($coverageFile)));
                }

                $output = \fopen($coverageFile, 'wb');

                if (!\is_resource($output)) {
                    throw new \RuntimeException(\sprintf('Could not write Infection coverage file "%s".', $coverageFile));
                }

                try {
                    \fwrite($output, \sprintf(
                        "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                        . "<phpunit xmlns=\"%s\">\n"
                        . "  <file name=\"%s\" path=\"%s\">\n"
                        . "    <totals><lines total=\"%d\" comments=\"0\" code=\"%d\" executable=\"%d\" executed=\"%d\" percent=\"%.2f\"/></totals>\n"
                        . "    <coverage>\n",
                        self::NAMESPACE,
                        $this->escape(\basename($file)),
                        $this->escape($directory),
                        $executable,
                        $executable,
                        $executable,
                        $executed,
                        $percentage,
                    ));

                    if (\is_file($state['spool'])) {
                        $spool = \fopen($state['spool'], 'rb');

                        if (!\is_resource($spool)) {
                            throw new \RuntimeException(\sprintf('Could not read Infection coverage spool for "%s".', $file));
                        }

                        try {
                            \stream_copy_to_stream($spool, $output);
                        } finally {
                            \fclose($spool);
                        }
                    }

                    \fwrite($output, "    </coverage>\n  </file>\n</phpunit>\n");
                } finally {
                    \fclose($output);
                }

                $indexFiles .= \sprintf(
                    "      <file name=\"%s\" href=\"%s\"/>\n",
                    $this->escape(\basename($file)),
                    $this->escape($state['href']),
                );
                $totalExecutable += $executable;
                $totalExecuted += $executed;
            }

            $percentage = $totalExecutable === 0 ? 0.0 : $totalExecuted / $totalExecutable * 100;
            $index = \sprintf(
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<phpunit xmlns=\"%s\">\n"
                . "  <project source=\"%s\">\n"
                . "    <directory name=\".\">\n"
                . "      <totals><lines total=\"%d\" comments=\"0\" code=\"%d\" executable=\"%d\" executed=\"%d\" percent=\"%.2f\"/></totals>\n"
                . "%s"
                . "    </directory>\n"
                . "  </project>\n"
                . "</phpunit>\n",
                self::NAMESPACE,
                $this->escape(\rtrim($root, '/')),
                $totalExecutable,
                $totalExecutable,
                $totalExecutable,
                $totalExecuted,
                $percentage,
                $indexFiles,
            );

            if (\file_put_contents(\rtrim($targetDirectory, '/') . '/index.xml', $index, \LOCK_EX) === false) {
                throw new \RuntimeException('Could not write Infection coverage index.');
            }
        } finally {
            $this->removeDirectory($spoolDirectory);
        }
    }

    /** @param array<string, mixed> $record */
    private function requiredString(array $record, string $key): string
    {
        $value = $record[$key] ?? null;

        if (!\is_string($value) || $value === '') {
            throw new \RuntimeException(\sprintf('The Greenlight coverage map has an invalid "%s" field.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $record @return list<positive-int> */
    private function positiveLines(array $record): array
    {
        $lines = $record['lines'] ?? null;

        if (!\is_array($lines) || !\array_is_list($lines)) {
            throw new \RuntimeException('The Greenlight coverage map has an invalid lines field.');
        }

        foreach ($lines as $line) {
            if (!\is_int($line) || $line < 1) {
                throw new \RuntimeException('The Greenlight coverage map contains an invalid line number.');
            }
        }

        return $lines;
    }

    private function relativeSourcePath(string $file, string $root): string
    {
        $prefix = \rtrim($root, '/') . '/';

        if (!\str_starts_with($file, $prefix)) {
            throw new \RuntimeException(\sprintf(
                'Covered source "%s" is outside the Greenlight project root "%s".',
                $file,
                $root,
            ));
        }

        return \substr($file, \strlen($prefix));
    }

    private function escape(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES | \ENT_XML1, 'UTF-8');
    }

    private function removeDirectory(string $directory): void
    {
        $files = \glob($directory . '/*');

        foreach ($files === false ? [] : $files as $file) {
            \unlink($file);
        }

        \rmdir($directory);
    }

    /** @return array<string, mixed> */
    private function decodeRecord(string $line): array
    {
        $decoded = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \RuntimeException('The Greenlight coverage map contains a non-object record.');
        }

        $record = [];

        foreach ($decoded as $key => $value) {
            if (!\is_string($key)) {
                throw new \RuntimeException('The Greenlight coverage map contains a record with a non-string key.');
            }

            $record[$key] = $value;
        }

        return $record;
    }
}
