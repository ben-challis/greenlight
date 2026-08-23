<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class SequentialFallbackTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    #[DataSet('unavailableParallelCapabilities')]
    public function unavailableParallelCapabilitiesFallBackToInProcess(string $function): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'sequential-fallback');
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--workers=4', '--reporter=plain'],
            phpArguments: ['-d', 'disable_functions=' . $function],
        );

        Expect::that($result->exitCode)
            ->because(\sprintf('the runner uses in-process execution when PHP disables %s', $function))
            ->toBe(0);
        Expect::that($result->output())->toContain('1 test, 1 passed')
            ->not()->toContain($function);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function unavailableParallelCapabilities(): iterable
    {
        yield 'process creation' => ['proc_open'];
        yield 'process status' => ['proc_get_status'];
        yield 'process termination' => ['proc_terminate'];
        yield 'process close' => ['proc_close'];
        yield 'socket server' => ['stream_socket_server'];
        yield 'socket name' => ['stream_socket_get_name'];
        yield 'socket accept' => ['stream_socket_accept'];
        yield 'socket client' => ['stream_socket_client'];
        yield 'socket selection' => ['stream_select'];
        yield 'socket blocking mode' => ['stream_set_blocking'];
    }
}
