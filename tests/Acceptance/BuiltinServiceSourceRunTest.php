<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Artifact\Attachments;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class BuiltinServiceSourceRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function attachmentsWithAnUnknownSourceDoNotUseTheBuiltinService(): void
    {
        $this->assertUnknownSource(Attachments::class, 'missing-attachments');
    }

    #[Test]
    public function cleanupWithAnUnknownSourceDoesNotUseTheBuiltinService(): void
    {
        $this->assertUnknownSource(Cleanup::class, 'missing-cleanup');
    }

    /** @param class-string $type */
    private function assertUnknownSource(string $type, string $source): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, $source);
        $project->writeFile('tests/BuiltinSourceTest.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace BuiltinSourceProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;
            use Greenlight\Harness\Service;

            final readonly class BuiltinSourceTest
            {
                public function __construct(#[Service(source: '%s')] private \%s $service) {}

                #[Test]
                public function receivesTheRequestedService(): void
                {
                    Expect::that(\is_object($this->service))->toBeTrue();
                }
            }

            PHP,
            $source,
            $type,
        ));
        $project->configureWithTestFiles(['tests/BuiltinSourceTest.php']);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--workers=1']);
        $output = $result->output();

        Expect::that($result->exitCode)
            ->because($output === '' ? 'The built-in source run returned no output.' : $output)
            ->toBe(1);
        Expect::that($output)->toContain('1 test, 0 passed, 1 errored');
        Expect::that($output)->toContain('source "' . $source . '"');
    }
}
