<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;

final readonly class AcceptanceProjectTest
{
    public function __construct(private TempDirectory $workspace) {}

    #[Test]
    public function createsAProjectAndWritesNestedFiles(): void
    {
        $project = AcceptanceProject::create($this->workspace, 'project');
        $project->writeFile('nested/example.txt', 'contents');

        Expect::that($project->directory)->because('creates a project and writes nested files')->toBe($this->workspace->path() . '/project');
        Expect::that($project->path('nested/example.txt'))->toBe($project->directory . '/nested/example.txt');
        Expect::that(\file_get_contents($project->path('nested/example.txt')))->toBe('contents');
    }

    #[Test]
    public function aBlockedParentDirectoryFailsWithTheTargetPath(): void
    {
        $project = AcceptanceProject::create($this->workspace, 'blocked-parent');
        $project->writeFile('blocked', 'keep');
        $parent = $project->path('blocked');

        Expect::that(static fn() => $project->writeFile('blocked/example.txt', 'contents'))
            ->because('a blocked parent directory MUST fail before the fixture continues')
            ->toThrow(
                \RuntimeException::class,
                matching: \sprintf('/^Failed to create acceptance project directory "%s"/', \preg_quote($parent, '/')),
            );
        Expect::that(\file_get_contents($parent))
            ->because('a failed directory creation MUST preserve the blocking file')
            ->toBe('keep');
    }

    #[Test]
    public function anUnwritableTargetFailsWithTheTargetPath(): void
    {
        $project = AcceptanceProject::create($this->workspace, 'blocked-target');
        $project->writeFile('blocked/seed.txt', 'keep');
        $target = $project->path('blocked');

        Expect::that(static fn() => $project->writeFile('blocked', 'contents'))
            ->because('an unwritable target MUST fail before the fixture continues')
            ->toThrow(
                \RuntimeException::class,
                matching: \sprintf('/^Failed to write acceptance project file "%s"/', \preg_quote($target, '/')),
            );
        Expect::that(\file_get_contents($project->path('blocked/seed.txt')))
            ->because('a failed file write MUST preserve the target directory contents')
            ->toBe('keep');
    }

    #[Test]
    #[DataSet('invalidProjectPaths')]
    public function projectFilesRejectNonPlainRelativePaths(string $relativePath): void
    {
        $project = AcceptanceProject::create($this->workspace, 'invalid-path');

        Expect::that(static fn() => $project->writeFile($relativePath, 'contents'))
            ->because('acceptance project writes MUST stay in the project directory')
            ->toThrow(
                \InvalidArgumentException::class,
                message: \sprintf(
                    'Acceptance project path "%s" must be a relative path of plain segments.',
                    $relativePath,
                ),
            );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProjectPaths(): iterable
    {
        yield 'empty path' => [''];
        yield 'absolute path' => ['/tmp/fixture.php'];
        yield 'current-directory segment' => ['./fixture.php'];
        yield 'parent-directory segment' => ['../fixture.php'];
        yield 'nested parent-directory segment' => ['tests/../fixture.php'];
        yield 'empty segment' => ['tests//fixture.php'];
        yield 'backslash separator' => ['tests\\fixture.php'];
        yield 'null byte' => ["tests/fixture\0.php"];
    }

    #[Test]
    public function configuresTheProjectWithTestFilesAndTheRequestedWorkerCount(): void
    {
        $project = AcceptanceProject::create($this->workspace, 'configured');
        $project->writeFile('tests/First.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__ . '/../loaded.txt', 'first');
            PHP);
        $project->writeFile('tests/Second.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__ . '/../loaded.txt', 'second', FILE_APPEND);
            PHP);
        $project->configureWithTestFiles(['tests/First.php', 'tests/Second.php'], workers: 3);

        $builder = require $project->path('greenlight.php');

        if (!$builder instanceof GreenlightConfig) {
            Fail::because(\sprintf(
                'Expected generated configuration "%s" to return GreenlightConfig, got %s.',
                $project->path('greenlight.php'),
                \get_debug_type($builder),
            ));
        }

        $configuration = $builder->build();
        $testsDirectory = \realpath($project->path('tests'));

        if ($testsDirectory === false) {
            Fail::because(\sprintf(
                'Expected generated tests directory at "%s".',
                $project->path('tests'),
            ));
        }

        Expect::that(\file_get_contents($project->path('loaded.txt')))->because('configures the project with test files and the requested worker count')->toBe('firstsecond');
        Expect::that($configuration->paths)->toBe([$testsDirectory]);
        Expect::that($configuration->workers->fixed)->toBe(3);
        Expect::that($configuration->randomizeOrder)->toBeFalse();
    }

    #[Test]
    public function escapesTestFilePathsInGeneratedConfiguration(): void
    {
        $project = AcceptanceProject::create($this->workspace, 'quoted-path');
        $project->writeFile("tests/O'Brien.php", <<<'PHP'
            <?php

            file_put_contents(__DIR__ . '/../loaded.txt', 'loaded');
            PHP);
        $project->configureWithTestFiles(["tests/O'Brien.php"]);

        $configuration = require $project->path('greenlight.php');

        Expect::that($configuration)->because('escapes test file paths in generated configuration')->toBeInstanceOf(GreenlightConfig::class);
        Expect::that(\file_get_contents($project->path('loaded.txt')))->toBe('loaded');
    }

    #[Test]
    public function projectWithDiscoveryBasicTestsTargetsTheSharedFixture(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->workspace, 'listing');
        $builder = require $project->path('greenlight.php');

        if (!$builder instanceof GreenlightConfig) {
            Fail::because(\sprintf(
                'Expected generated configuration "%s" to return GreenlightConfig, got %s.',
                $project->path('greenlight.php'),
                \get_debug_type($builder),
            ));
        }

        Expect::that($builder->build()->paths)->because('project with discovery basic tests targets the shared fixture')->toBe([
            \dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic',
        ]);
    }
}
