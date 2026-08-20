<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\ProcessResult;
use Greenlight\Tests\Support\ProjectFiles;
use Greenlight\Tests\Support\Subprocess;

final readonly class DocsPhpCheckTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function extractsDeterministicFilesAndWritesLineMappings(): void
    {
        $project = $this->project('extract');
        $project->write(
            'docs/example.md',
            <<<'MARKDOWN'
            # Example

            <!-- php-example {"example":"configuration","file":"config/example.php","mode":"file","tools":[]} -->
            ```php
            return Configuration::create();
            ```

            MARKDOWN,
        );

        $result = $this->run($project, 'extract');
        Expect::that($result->exitCode)->because('extracts the selected PHP fence')->toBe(0);
        Expect::that($this->read($project, 'build/docs-php/configuration/config/example.php'))
            ->toBe("<?php\nreturn Configuration::create();\n");

        /** @var array{version: int, snippets: list<array<string, mixed>>} $manifest */
        $manifest = \json_decode(
            $this->read($project, 'build/docs-php/manifest.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        Expect::that($manifest['version'])->toBe(1);
        Expect::that($manifest['snippets'])->toHaveCount(1);
        Expect::that($manifest['snippets'][0]['example'])->toBe('configuration');
        Expect::that($manifest['snippets'][0]['virtualFile'])->toBe('config/example.php');
        Expect::that($manifest['snippets'][0]['generatedPath'])
            ->toBe('build/docs-php/configuration/config/example.php');
        Expect::that($manifest['snippets'][0]['source'])->toContainSubset([
            'path' => 'docs/example.md',
            'fenceLine' => 4,
            'startLine' => 5,
            'endLine' => 5,
        ]);
        Expect::that($manifest['snippets'][0]['mappings'])->toBe([[
            'generatedStartLine' => 2,
            'generatedEndLine' => 2,
            'sourceStartLine' => 5,
        ]]);
    }

    #[Test]
    public function translatesPhpStanAndRectorResultsToMarkdown(): void
    {
        $project = $this->project('tool-results');
        $project->write(
            'docs/example.md',
            <<<'MARKDOWN'
            <!-- php-example {"example":"example","file":"example.php","mode":"file","tools":["phpstan","rector"]} -->
            ```php
            $value = unknown();
            $next = legacy();
            ```

            MARKDOWN,
        );
        $this->writePhpStanFinding($project, 2);
        $this->writeRectorFinding($project);

        $result = $this->run(
            $project,
            'check',
            '--phpstan-bin=' . $project->path('phpstan.php'),
            '--rector-bin=' . $project->path('rector.php'),
        );

        Expect::that($result->exitCode)->because('reports translated tool findings')->toBe(1);
        Expect::that($result->stdout)
            ->toContain('docs/example.md:3: PHPStan [function.notFound]: Function unknown not found.')
            ->toContain('docs/example.md:4: Rector: Rector would change this example. Applied rules: ExampleRector.');
    }

    #[Test]
    public function mapsStatementWrapperLinesToMarkdown(): void
    {
        $project = $this->project('statement-wrapper');
        $project->write(
            'docs/example.md',
            <<<'MARKDOWN'
            <!-- php-example {"example":"statement","file":"statement.php","mode":"statements","tools":["phpstan"]} -->
            ```php
            $value = unknown();
            ```

            MARKDOWN,
        );
        $this->writePhpStanFinding($project, 4);

        $result = $this->run(
            $project,
            'check',
            '--phpstan-bin=' . $project->path('phpstan.php'),
        );

        Expect::that($result->exitCode)->because('maps a statement wrapper line')->toBe(1);
        Expect::that($result->stdout)
            ->toContain('docs/example.md:3: PHPStan [function.notFound]: Function unknown not found.');
    }

    #[Test]
    public function wrapsLeadingFluentChainsAndImportedClassMembers(): void
    {
        $project = $this->project('fragment-wrappers');
        $project->write(
            'docs/example.md',
            <<<'MARKDOWN'
            <!-- php-example {"example":"chain","file":"chain.php","mode":"statements","tools":[]} -->
            ```php
            ->first()
                ->second()
            ```

            <!-- php-example {"example":"member","file":"member.php","mode":"class-members","tools":[]} -->
            ```php
            use Example\Dependency;

            public function __construct(private Dependency $dependency) {}
            ```

            MARKDOWN,
        );

        $result = $this->run($project, 'extract');
        Expect::that($result->exitCode)->because('wraps common documentation fragments')->toBe(0);
        Expect::that($this->read($project, 'build/docs-php/chain/chain.php'))->toBe(
            "<?php\n\n(static function () {\n    \$value\n    ->first()\n        ->second();\n})();\n",
        );
        Expect::that($this->read($project, 'build/docs-php/member/member.php'))->toContain(
            "use Example\\Dependency;\nfinal class DocsExample_",
        )->toContain(
            "    public function __construct(private Dependency \$dependency) {}\n}\n",
        );
    }

    #[Test]
    public function rendersGithubAnnotationsWhenRequested(): void
    {
        $project = $this->project('github-annotations');
        $project->write(
            'docs/example.md',
            <<<'MARKDOWN'
            <!-- php-example {"example":"example","file":"example.php","mode":"file","tools":["phpstan"]} -->
            ```php
            $value = unknown();
            ```

            MARKDOWN,
        );
        $this->writePhpStanFinding($project, 2);

        $result = $this->runWithEnvironment(
            $project,
            ['GITHUB_ACTIONS' => 'true'],
            'check',
            '--phpstan-bin=' . $project->path('phpstan.php'),
        );

        Expect::that($result->exitCode)->because('reports the workflow annotation')->toBe(1);
        Expect::that($result->stdout)->toContain(
            '::error file=docs/example.md,line=3::PHPStan [function.notFound]%3A Function unknown not found.',
        );
    }

    #[Test]
    public function rejectsDuplicateGeneratedFiles(): void
    {
        $project = $this->project('duplicate-file');
        $project->write(
            'docs/example.md',
            <<<'MARKDOWN'
            <!-- php-example {"example":"duplicate","file":"example.php","mode":"file","tools":[]} -->
            ```php
            return 1;
            ```

            <!-- php-example {"example":"duplicate","file":"example.php","mode":"file","tools":[]} -->
            ```php
            return 2;
            ```

            MARKDOWN,
        );

        $result = $this->run($project, 'extract');
        Expect::that($result->exitCode)->because('rejects a duplicate virtual file')->toBe(1);
        Expect::that($result->stderr)->toContain(
            'docs/example.md:6: Generated file "duplicate/example.php" is already selected by docs/example.md:1.',
        );
    }

    #[Test]
    public function excludesGeneratedDocumentsAndRejectsUnclassifiedFences(): void
    {
        $project = $this->project('inventory');
        $project->write(
            'docs/generated.md',
            <<<'MARKDOWN'
            <!-- This file is generated by example.php. -->

            ```php
            partial signature
            ```

            MARKDOWN,
        );
        $project->write(
            'docs/manual.md',
            <<<'MARKDOWN'
            ```php
            partial fragment
            ```

            MARKDOWN,
        );

        $result = $this->run($project, 'check');
        Expect::that($result->exitCode)->because('requires a decision for each manual PHP fence')->toBe(1);
        Expect::that($result->stderr)->toContain(
            'docs/manual.md:1: PHP fence requires php-example metadata.',
        );
    }

    private function project(string $name): ProjectFiles
    {
        return ProjectFiles::create($this->tempDirectory, $name . '/project');
    }

    private function read(ProjectFiles $project, string $relativePath): string
    {
        $contents = \file_get_contents($project->path($relativePath));

        if ($contents === false) {
            throw new \RuntimeException(\sprintf('Cannot read test file "%s".', $relativePath));
        }

        return $contents;
    }

    private function writePhpStanFinding(ProjectFiles $project, int $line): void
    {
        $project->write(
            'phpstan.php',
            <<<PHP
            <?php

            declare(strict_types=1);

            \$directory = \$argv[\array_key_last(\$argv)];
            \$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\$directory));

            foreach (\$files as \$file) {
                if (\$file->isFile() && \$file->getExtension() === 'php') {
                    echo \json_encode([
                        'totals' => ['errors' => 0, 'file_errors' => 1],
                        'files' => [\$file->getPathname() => [
                            'errors' => 1,
                            'messages' => [[
                                'message' => 'Function unknown not found.',
                                'line' => {$line},
                                'identifier' => 'function.notFound',
                            ]],
                        ]],
                        'errors' => [],
                    ], \JSON_THROW_ON_ERROR);

                    exit(1);
                }
            }

            PHP,
        );
    }

    private function writeRectorFinding(ProjectFiles $project): void
    {
        $project->write(
            'rector.php',
            <<<'PHP'
            <?php

            declare(strict_types=1);

            $directory = $argv[2];
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    echo json_encode([
                        'totals' => ['changed_files' => 1, 'errors' => 0],
                        'file_diffs' => [[
                            'file' => $file->getPathname(),
                            'diff' => '@@ -3 +3 @@',
                            'applied_rectors' => ['Example\\ExampleRector'],
                        ]],
                        'changed_files' => [$file->getPathname()],
                    ], JSON_THROW_ON_ERROR);

                    exit(2);
                }
            }

            PHP,
        );
    }

    private function run(ProjectFiles $project, string $command, string ...$arguments): ProcessResult
    {
        return $this->runWithEnvironment(
            $project,
            ['GITHUB_ACTIONS' => 'false'],
            $command,
            ...$arguments,
        );
    }

    /**
     * @param array<string, string> $environment
     */
    private function runWithEnvironment(
        ProjectFiles $project,
        array $environment,
        string $command,
        string ...$arguments,
    ): ProcessResult {
        $processCommand = [
            \PHP_BINARY,
            \dirname(__DIR__, 2) . '/tools/docs-php.php',
            $command,
            '--root=' . $project->directory,
        ];

        foreach ($arguments as $argument) {
            $processCommand[] = $argument;
        }

        return Subprocess::run($project->directory, $processCommand, $environment);
    }
}
