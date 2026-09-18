<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;

final readonly class AcceptanceProjectTest
{
    public function __construct(private TemporaryDirectory $workspace) {}

    #[Test]
    public function createsAProjectAndWritesNestedFiles(): void
    {
        $project = AcceptanceProject::create($this->workspace, 'project');
        $project->writeFile('nested/example.txt', 'contents');

        Expect::value($project->directory)->because('creates a project and writes nested files')->toBe($this->workspace->path() . '/project');
        Expect::value($project->path('nested/example.txt'))->toBe($project->directory . '/nested/example.txt');
        Expect::value(\file_get_contents($project->path('nested/example.txt')))->toBe('contents');
    }

    #[Test]
    public function aBlockedParentDirectoryFailsWithTheTargetPath(): void
    {
        $project = AcceptanceProject::create($this->workspace, 'blocked-parent');
        $project->writeFile('blocked', 'keep');
        $parent = $project->path('blocked');

        Expect::calling(static fn() => $project->writeFile('blocked/example.txt', 'contents'))
            ->because('a blocked parent directory MUST fail before the fixture continues')
            ->toThrow(
                \RuntimeException::class,
                matching: \sprintf('/^Failed to create acceptance project directory "%s"/', \preg_quote($parent, '/')),
            );
        Expect::value(\file_get_contents($parent))
            ->because('a failed directory creation MUST preserve the blocking file')
            ->toBe('keep');
    }

    #[Test]
    public function anUnwritableTargetFailsWithTheTargetPath(): void
    {
        $project = AcceptanceProject::create($this->workspace, 'blocked-target');
        $project->writeFile('blocked/seed.txt', 'keep');
        $target = $project->path('blocked');

        Expect::calling(static fn() => $project->writeFile('blocked', 'contents'))
            ->because('an unwritable target MUST fail before the fixture continues')
            ->toThrow(
                \RuntimeException::class,
                matching: \sprintf('/^Failed to write acceptance project file "%s"/', \preg_quote($target, '/')),
            );
        Expect::value(\file_get_contents($project->path('blocked/seed.txt')))
            ->because('a failed file write MUST preserve the target directory contents')
            ->toBe('keep');
    }

    #[Test]
    #[DataSet('invalidProjectPaths')]
    public function projectFilesRejectNonPlainRelativePaths(string $relativePath): void
    {
        $project = AcceptanceProject::create($this->workspace, 'invalid-path');

        Expect::calling(static fn() => $project->writeFile($relativePath, 'contents'))
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

        Expect::value($builder)
            ->because(\sprintf(
                'The generated configuration "%s" MUST return GreenlightConfig.',
                $project->path('greenlight.php'),
            ))
            ->toBeInstanceOf(GreenlightConfig::class);

        $configuration = $builder->build();
        $testsDirectory = \realpath($project->path('tests'));

        Expect::value($testsDirectory)
            ->because(\sprintf(
                'The generated tests directory at "%s" MUST exist.',
                $project->path('tests'),
            ))
            ->toBeString();

        Expect::value(\file_get_contents($project->path('loaded.txt')))->because('configures the project with test files and the requested worker count')->toBe('firstsecond');
        Expect::value($configuration->discovery->paths)->toBe([$testsDirectory]);
        Expect::value($configuration->workers->count->fixed)->toBe(3);
        Expect::value($configuration->order->randomized)->toBeFalse();
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

        Expect::value($configuration)->because('escapes test file paths in generated configuration')->toBeInstanceOf(GreenlightConfig::class);
        Expect::value(\file_get_contents($project->path('loaded.txt')))->toBe('loaded');
    }

    #[Test]
    public function projectWithDiscoveryBasicTestsTargetsTheSharedFixture(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->workspace, 'listing');
        $builder = require $project->path('greenlight.php');

        Expect::value($builder)
            ->because(\sprintf(
                'The generated configuration "%s" MUST return GreenlightConfig.',
                $project->path('greenlight.php'),
            ))
            ->toBeInstanceOf(GreenlightConfig::class);

        Expect::value($builder->build()->discovery->paths)->because('project with discovery basic tests targets the shared fixture')->toBe([
            \dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic',
        ]);
    }

    #[Test]
    public function passingProjectPresetsHaveSmallSuitesAndDeterministicNamespaces(): void
    {
        $one = AcceptanceProject::createWithOnePassingTest($this->workspace, 'one-passing-test');
        $two = AcceptanceProject::createWithTwoPassingTests($this->workspace, 'two-passing-tests');
        $sameOne = AcceptanceProject::createWithOnePassingTest($this->workspace, 'one-passing-test');
        $oneConfiguration = require $one->path('greenlight.php');
        $twoConfiguration = require $two->path('greenlight.php');
        $discoverer = new TestDiscoverer();

        Expect::value($oneConfiguration)
            ->because('the one-test preset MUST generate a Greenlight configuration')
            ->toBeInstanceOf(GreenlightConfig::class);
        Expect::value($twoConfiguration)
            ->because('the two-test preset MUST generate a Greenlight configuration')
            ->toBeInstanceOf(GreenlightConfig::class);
        Expect::value($discoverer->discover($oneConfiguration->build()->discovery->paths)->classes())
            ->because('the one-test preset MUST contain one generated test class')
            ->toBe($one->testClasses());
        Expect::value($discoverer->discover($twoConfiguration->build()->discovery->paths)->classes())
            ->because('the two-test preset MUST contain two generated test classes')
            ->toBe($two->testClasses());
        Expect::value($one->testClasses())
            ->because('the same project name MUST produce the same test namespace')
            ->toBe($sameOne->testClasses());
        Expect::value(\array_intersect($one->testClasses(), $two->testClasses()))
            ->because('different project names MUST produce unique test namespaces')
            ->toBe([]);
    }
}
