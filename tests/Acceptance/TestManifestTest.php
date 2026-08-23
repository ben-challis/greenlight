<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use JsonSchema\Validator;

final readonly class TestManifestTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function jsonFormatWritesTheSelectedVersionedPlanWithoutExecution(): void
    {
        $project = $this->writeRichProject();
        $arguments = [
            'list-tests',
            '--format=json',
            '--suite=unit',
            '--group=manifest',
            '--exclude-group=excluded',
            '--test-id=ManifestProbe\\RichTest::describes[card]',
            '--test-id=ManifestProbe\\ParallelTest::describes',
            '--seed=7',
            '--shard=1/1',
        ];
        $first = GreenlightCli::run($project->directory, $arguments);
        $second = GreenlightCli::run($project->directory, $arguments);

        Expect::that($first->exitCode)->toBe(0);
        Expect::that($second->stdout)
            ->because('the same selected plan MUST produce the same manifest')
            ->toBe($first->stdout);
        Expect::that($first->stderr)
            ->because('configuration and provider output MUST stay off standard output')
            ->toContain('configuration diagnostic')
            ->toContain('provider diagnostic');
        Expect::that(\is_file($project->path('executed')))
            ->because('manifest discovery MUST NOT execute a test')
            ->toBeFalse();

        $decoded = $this->decode($first->stdout);
        Expect::that($decoded['version'] ?? null)->toBe(1);
        Expect::that($decoded['order'] ?? null)->toBe([
            'tests' => 'plan',
            'completion' => 'not-applicable',
            'seed' => 7,
        ]);
        Expect::that($decoded['shard'] ?? null)->toBe(['index' => 1, 'count' => 1]);
        $tests = $decoded['tests'] ?? null;

        if (!\is_array($tests)) {
            throw new \RuntimeException('Manifest tests must be an array.');
        }

        Expect::that($tests)->toHaveCount(2);
        $byId = $this->testsById($tests);

        $rich = $byId['ManifestProbe\\RichTest::describes[card]'] ?? null;

        if (!\is_array($rich)) {
            throw new \RuntimeException('The rich manifest test is missing.');
        }

        $source = $rich['source'] ?? null;

        if (!\is_array($source)) {
            throw new \RuntimeException('The rich manifest source is missing.');
        }

        Expect::that($rich['dataSetKey'] ?? null)->toBe('card');
        Expect::that($source['file'] ?? null)->toBe($project->path('tests/Unit/RichTest.php'));
        Expect::that($source['line'] ?? null)->toBeInt()->toBeGreaterThan(0);
        Expect::that($rich['groups'] ?? null)->toBe(['manifest']);
        Expect::that($rich['suites'] ?? null)->toBe(['all', 'unit']);
        Expect::that($rich['skip'] ?? null)->toBe([
            'present' => true,
            'condition' => EnvironmentVariableEquals::class,
        ]);
        Expect::that($rich['retry'] ?? null)->toBe([
            'additionalAttempts' => 2,
            'onlyOn' => 'RuntimeException',
        ]);
        Expect::that($rich['timeoutSeconds'] ?? null)->toBe(1.5);
        Expect::that($rich['captureOutput'] ?? null)->toBeFalse();
        Expect::that($rich['noExpectations'] ?? null)->toBeTrue();
        Expect::that($rich['resources'] ?? null)->toBe(['database']);
        Expect::that($rich['isolated'] ?? null)->toBeTrue();
        Expect::that($rich['allowParallel'] ?? null)->toBeFalse();
        $parallel = $byId['ManifestProbe\\ParallelTest::describes'] ?? null;

        if (!\is_array($parallel)) {
            throw new \RuntimeException('The parallel manifest test is missing.');
        }

        Expect::that($parallel['allowParallel'] ?? null)->toBeTrue();
        Expect::that($first->stdout)
            ->because('the manifest MUST omit private skip metadata')
            ->not()
            ->toContain('skip-secret')
            ->not()
            ->toContain('condition-secret');

        $validator = new Validator();
        $schemaDocument = \json_decode($first->stdout, flags: \JSON_THROW_ON_ERROR);
        $validator->validate(
            $schemaDocument,
            (object) ['$ref' => 'file://' . \dirname(__DIR__, 2) . '/resources/schema/test-manifest-v1.schema.json'],
        );
        Expect::that($validator->isValid())
            ->because('the emitted manifest MUST match the shipped schema')
            ->toBeTrue();
    }

    #[Test]
    public function jsonFormatUsesAValidEmptyDocumentForZeroTests(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'manifest-empty');
        $result = GreenlightCli::run($project->directory, [
            'list-tests',
            '--format=json',
            '--filter=does-not-exist',
        ]);
        $decoded = $this->decode($result->stdout);

        Expect::that($result->exitCode)
            ->because('an empty manifest MUST be a successful discovery result')
            ->toBe(0);
        Expect::that($decoded['tests'] ?? null)->toBe([]);
    }

    #[Test]
    public function jsonFormatKeepsDiscoveryErrorsOffStandardOutput(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'manifest-error');
        $project->writeFile('tests/NoClassTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ManifestDiscoveryError;

            function helper(): void {}
            PHP);
        $project->configureWithTestFiles(['tests/NoClassTest.php']);
        $result = GreenlightCli::run($project->directory, [
            'list-tests',
            '--format=json',
            '--no-ansi',
        ]);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->stdout)
            ->because('a discovery error MUST NOT write a partial JSON document')
            ->toBe('');
        Expect::that($result->stderr)
            ->toContain('does not declare a class, interface, trait, or enum.');
    }

    private function writeRichProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'manifest-rich');
        $project->writeFile('tests/Unit/RichTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ManifestProbe;

            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Group;
            use Greenlight\Attribute\Isolated;
            use Greenlight\Attribute\NoExpectations;
            use Greenlight\Attribute\RequiresResource;
            use Greenlight\Attribute\Retry;
            use Greenlight\Attribute\Skip;
            use Greenlight\Attribute\SkipUnless;
            use Greenlight\Attribute\Test;
            use Greenlight\Attribute\Timeout;
            use Greenlight\Condition\EnvironmentVariableEquals;

            final class RichTest
            {
                #[Test(capture: false)]
                #[Group('manifest')]
                #[DataSet('rows')]
                #[Skip('skip-secret')]
                #[SkipUnless(EnvironmentVariableEquals::class, 'MANIFEST_NAME', 'condition-secret')]
                #[Retry(2, \RuntimeException::class)]
                #[Timeout(1.5)]
                #[NoExpectations]
                #[RequiresResource('database')]
                #[Isolated]
                public function describes(): void
                {
                    file_put_contents(__DIR__ . '/../../executed', 'yes');
                }

                public static function rows(): iterable
                {
                    echo "provider diagnostic\n";
                    yield 'card' => [];
                }
            }
            PHP);
        $project->writeFile('tests/Unit/ParallelTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ManifestProbe;

            use Greenlight\Attribute\AllowParallel;
            use Greenlight\Attribute\Group;
            use Greenlight\Attribute\Test;

            #[AllowParallel]
            final class ParallelTest
            {
                #[Test]
                #[Group('manifest')]
                public function describes(): void
                {
                    file_put_contents(__DIR__ . '/../../executed', 'yes');
                }
            }
            PHP);
        $project->writeFile('tests/Unit/ExcludedTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ManifestProbe;

            use Greenlight\Attribute\Group;
            use Greenlight\Attribute\Test;

            final class ExcludedTest
            {
                #[Test]
                #[Group('excluded')]
                public function excluded(): void {}
            }
            PHP);
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Config\SuiteBuilder;

            echo "configuration diagnostic\n";

            require_once __DIR__ . '/tests/Unit/RichTest.php';
            require_once __DIR__ . '/tests/Unit/ParallelTest.php';
            require_once __DIR__ . '/tests/Unit/ExcludedTest.php';

            return GreenlightConfig::create()
                ->suite('unit', static fn(SuiteBuilder $suite) => $suite->in('tests/Unit')->tag('fast'))
                ->suite('all', static fn(SuiteBuilder $suite) => $suite->in('tests')->tag('broad'));
            PHP);

        return $project;
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $decoded = \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \RuntimeException('Manifest JSON must decode to an object.');
        }

        $document = [];

        foreach ($decoded as $key => $value) {
            $document[(string) $key] = $value;
        }

        return $document;
    }

    /**
     * @param array<mixed> $tests
     * @return array<string, array<mixed>>
     */
    private function testsById(array $tests): array
    {
        $byId = [];

        foreach ($tests as $test) {
            if (\is_array($test) && \is_string($test['id'] ?? null)) {
                $byId[$test['id']] = $test;
            }
        }

        return $byId;
    }
}
