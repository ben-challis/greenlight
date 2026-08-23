<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Execution\ProcessPool\Worker\WorkerProcess;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\Subprocess;

final readonly class WorkerArtifactSessionTest
{
    public function __construct(
        private EnvironmentVariables $environment,
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    #[Timeout(5.0)]
    public function assignmentArtifactSettingsStageWorkerEvidence(): void
    {
        $root = \dirname(__DIR__, 5);
        $artifactRoot = $this->tempDirectory->subdirectory('worker-artifact-session');
        $server = Subprocess::start($root, [
            \PHP_BINARY,
            '-r',
            <<<'PHP'
            require $argv[1];

            $artifactRoot = $argv[2];
            $socketPath = '/tmp/greenlight-worker-' . bin2hex(random_bytes(6)) . '.sock';
            register_shutdown_function(static fn() => @unlink($socketPath));
            $address = 'unix://' . $socketPath;
            $server = stream_socket_server($address);

            if (!is_resource($server)) {
                exit(2);
            }

            fwrite(STDOUT, $address . "\n");
            fflush(STDOUT);

            $connection = stream_socket_accept($server, 2.0);

            if (!is_resource($connection)) {
                exit(3);
            }

            $channel = new Greenlight\Execution\ProcessPool\Protocol\SocketChannel($connection);

            if (!$channel->receive(2.0) instanceof Greenlight\Execution\ProcessPool\Protocol\Messages\Hello) {
                exit(4);
            }

            if (class_exists(Greenlight\Execution\ProcessPool\Protocol\Messages\Bootstrap::class)) {
                $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Bootstrap(
                    1,
                    null,
                    Greenlight\IntegrationFixture\IntegrationResources::empty(),
                ));

                if (!$channel->receive(2.0) instanceof Greenlight\Execution\ProcessPool\Protocol\Messages\Ready) {
                    exit(5);
                }
            }

            $class = Greenlight\Tests\Fixture\WorkerProcess\WorkerAttachmentTest::class;
            $id = new Greenlight\Test\TestId($class, 'recordsEvidence');
            $session = new Greenlight\Execution\Artifact\ArtifactSession(
                $artifactRoot . '/staging',
                $artifactRoot . '/public',
            );
            $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Assign(
                new Greenlight\Discovery\Plan\ExecutionPlan([
                    new Greenlight\Discovery\Plan\PlanEntry(
                        new Greenlight\Test\TestDefinition(
                            $class,
                            'recordsEvidence',
                            execution: new Greenlight\Test\ExecutionPolicy(noExpectations: true),
                        ),
                    ),
                ]),
                artifactSession: $session,
                artifactConfiguration: new Greenlight\Config\ArtifactConfiguration(
                    $artifactRoot . '/published',
                ),
            ));

            $finished = null;
            $done = null;

            do {
                $message = $channel->receive(2.0);

                if ($message instanceof Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope
                    && $message->event instanceof Greenlight\Event\TestFinished
                ) {
                    $finished = $message->event;
                }

                if ($message instanceof Greenlight\Execution\ProcessPool\Protocol\Messages\Done) {
                    $done = $message;
                }
            } while ($message instanceof Greenlight\Execution\ProcessPool\Protocol\Message && $done === null);

            $attachment = $finished?->result->attachments[0] ?? null;

            if (!$done instanceof Greenlight\Execution\ProcessPool\Protocol\Messages\Done
                || $done->summary->passed !== 1
                || !$attachment instanceof Greenlight\Artifact\StagedAttachment
                || file_get_contents($session->stagingDirectory . '/' . $attachment->storageKey) !== 'worker evidence'
            ) {
                exit(6);
            }

            $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Drain());
            PHP,
            $root . '/vendor/autoload.php',
            $artifactRoot,
        ]);
        $this->cleanup->defer($server->terminate(...));

        $address = \trim($server->readStdoutUntil("\n", 2.0));

        if ($address === '') {
            Fail::because('Worker protocol server did not publish its address.');
        }

        $this->environment->set('GREENLIGHT_CHANNEL', '1');
        $workerExit = new WorkerProcess(0.01)->run($address, 'worker-under-test', 'token');
        $serverExit = $server->wait(2.0)->exitCode;

        Expect::that($workerExit)
            ->because('a worker with artifact settings MUST complete its assignment')
            ->toBe(0);
        Expect::that($serverExit)
            ->because('the worker MUST stage evidence in the assigned artifact session')
            ->toBe(0);
    }
}
