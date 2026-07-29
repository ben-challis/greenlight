<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class SequentialFallbackTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    #[DataSet('unavailableParallelCapabilities')]
    public function unavailableParallelCapabilitiesFallBackToInProcess(string $function): void
    {
        // An isolated project prevents a conflict with another acceptance
        // test in the same directory.
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'sequential-fallback');
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--workers=4', '--reporter=plain'],
            phpArguments: ['-d', 'disable_functions=' . $function],
        );

        Expect::that($result->exitCode)
            ->because(\sprintf('the runner uses in-process execution when PHP disables %s', $function))
            ->toBe(0)
            ->and($result->output())->toContain('7 tests, 7 passed')
            ->not()->toContain($function);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function unavailableParallelCapabilities(): iterable
    {
        yield 'process creation' => ['proc_open'];
        yield 'socket server' => ['stream_socket_server'];
    }
}
