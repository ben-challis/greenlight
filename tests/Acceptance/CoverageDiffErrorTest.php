<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CoverageDiffErrorTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    #[DataSet('invalidExportLabels')]
    public function malformedCoverageExportsNameTheirRole(string $invalidLabel): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'coverage-diff-error-' . $invalidLabel);
        $valid = '{"v":1,"files":{}}';
        $invalid = '{"v":1,"files":"invalid"}';

        foreach (['baseline', 'current'] as $label) {
            $project->writeFile($label . '.json', $label === $invalidLabel ? $invalid : $valid);
        }

        $result = GreenlightCli::run($project->directory, [
            'coverage:diff',
            '--baseline=baseline.json',
            '--current=current.json',
        ]);

        Expect::that($result->exitCode)
            ->because('malformed coverage exports name their role')
            ->toBe(1)
            ->and($result->output())
            ->toContain(\sprintf(
                'The %s file is not a valid coverage export: '
                . 'Coverage JSON document is invalid: use an object for "files".',
                $invalidLabel,
            ));
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function invalidExportLabels(): iterable
    {
        yield 'baseline' => ['baseline'];
        yield 'current' => ['current'];
    }
}
