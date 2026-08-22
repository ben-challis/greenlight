<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Result\TestResult;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;

final readonly class AttachmentRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function attachmentsSurviveASequentialRunWithRetryAndPluginEvidence(): void
    {
        $this->assertRun(1);
    }

    #[Test]
    public function attachmentsCrossTheParallelWorkerBoundary(): void
    {
        $this->assertRun(2);
    }

    private function assertRun(int $workers): void
    {
        $project = $this->project('attachments-' . $workers);
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--reporter=jsonl', '--workers=' . $workers],
        );

        Expect::that($result->exitCode)->toBe(1);

        /** @var array<string, TestResult> $results */
        $results = [];
        $artifactsDirectory = null;

        foreach (JsonlEvents::from($result) as $event) {
            if ($event instanceof RunStarted) {
                $artifactsDirectory = $event->artifactsDirectory;
            }

            if (!$event instanceof TestFinished) {
                continue;
            }

            $results[$event->result->id->method] = $event->result;
        }

        if ($artifactsDirectory === null) {
            throw new \RuntimeException('The run did not report an artifacts directory.');
        }

        $projectDirectory = (string) \realpath($project->directory);

        Expect::that($artifactsDirectory)->toBe($projectDirectory . '/artifacts/' . \basename($artifactsDirectory));
        Expect::that($results)
            ->toHaveKey('failsWithEvidence')
            ->toHaveKey('passesWithAlwaysEvidence')
            ->toHaveKey('passesWithoutRetainingDefaultEvidence')
            ->toHaveKey('retainsTheFailedRetryAttempt')
            ->toHaveKey('becomesErroredDuringClassTeardown')
            ->toHaveKey('retryDeciderThrows');

        Expect::that($results['failsWithEvidence']->attachments)->toHaveCount(3);
        Expect::that($results['passesWithAlwaysEvidence']->attachments)->toHaveCount(1);
        Expect::that($results['passesWithoutRetainingDefaultEvidence']->attachments)->toBe([]);
        Expect::that($results['retainsTheFailedRetryAttempt']->attempts)->toBe(2);
        Expect::that($results['retainsTheFailedRetryAttempt']->attachments)->toHaveCount(2);
        Expect::that($results['retainsTheFailedRetryAttempt']->attachments[0]->attempt)->toBe(1);
        Expect::that($results['becomesErroredDuringClassTeardown']->outcome->isSuccessful())->toBeFalse();
        Expect::that($results['becomesErroredDuringClassTeardown']->attachments)->toHaveCount(1);
        Expect::that($results['retryDeciderThrows']->outcome->isSuccessful())->toBeFalse();
        Expect::that($results['retryDeciderThrows']->attachments)->toHaveCount(2);

        foreach ($results as $testResult) {
            foreach ($testResult->attachments as $attachment) {
                Expect::that(\is_file($attachment->path))
                    ->because(\sprintf(
                        'The attachment for test "%s" MUST exist at "%s".',
                        $testResult->id,
                        $attachment->path,
                    ))
                    ->toBeTrue();
                Expect::that(\hash_file('sha256', $attachment->path))
                    ->because(\sprintf(
                        'The SHA-256 digest for the attachment from test "%s" MUST match the file at "%s".',
                        $testResult->id,
                        $attachment->path,
                    ))
                    ->toBe($attachment->sha256);
            }
        }
    }

    private function project(string $name): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, $name);
        $project->writeFile('tests/AttachmentProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace AttachmentProbe;

            use Greenlight\Attribute\Retry;
            use Greenlight\Attribute\Test;
            use Greenlight\Artifact\AttachmentRetention;
            use Greenlight\Artifact\Attachments;
            use Greenlight\Expect\Expect;

            final readonly class AttachmentProbeTest
            {
                public function __construct(private Attachments $attachments) {}

                #[Test]
                public function failsWithEvidence(): never
                {
                    $source = tempnam(sys_get_temp_dir(), 'greenlight-attachment-');
                    file_put_contents($source, "\x00source");
                    $this->attachments->file('snapshot.bin', $source);
                    unlink($source);
                    $this->attachments->value('response.json', ['status' => 500]);

                    throw new \RuntimeException('intentional attachment failure');
                }

                #[Test]
                public function passesWithAlwaysEvidence(): void
                {
                    $this->attachments->text(
                        'always.txt',
                        'kept',
                        retention: AttachmentRetention::Always,
                    );
                    Expect::that(true)->toBeTrue();
                }

                #[Test]
                public function passesWithoutRetainingDefaultEvidence(): void
                {
                    $this->attachments->text('discarded.txt', 'discarded');
                    Expect::that(true)->toBeTrue();
                }

                #[Test]
                #[Retry(1)]
                public function retainsTheFailedRetryAttempt(): void
                {
                    static $attempt = 0;
                    ++$attempt;
                    $this->attachments->text('attempt.txt', 'attempt ' . $attempt);

                    if ($attempt === 1) {
                        throw new \RuntimeException('retry me');
                    }

                    Expect::that(true)->toBeTrue();
                }

                #[Test]
                public function retryDeciderThrows(): never
                {
                    $this->attachments->text('before-decider.txt', 'keep this');

                    throw new \RuntimeException('failure presented to decider');
                }
            }
            PHP);
        $project->writeFile('tests/TeardownAttachmentTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace AttachmentProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Artifact\Attachments;
            use Greenlight\Expect\Expect;
            use Greenlight\Harness\Disposable;

            final class FailingClassResource implements Disposable
            {
                public function use(): void {}

                public function dispose(): never
                {
                    throw new \RuntimeException('class teardown failed');
                }
            }

            final readonly class TeardownAttachmentTest
            {
                public function __construct(
                    private Attachments $attachments,
                    private FailingClassResource $resource,
                ) {}

                #[Test]
                public function becomesErroredDuringClassTeardown(): void
                {
                    $this->attachments->text('before-teardown.txt', 'keep this');
                    $this->resource->use();
                    Expect::that(true)->toBeTrue();
                }
            }
            PHP);
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\ArtifactBuilder;
            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Result\TestResult;
            use Greenlight\Test\RetryPolicy;
            use Greenlight\Harness\Scope;
            use Greenlight\Harness\ServiceDefinition;
            use Greenlight\Plugin\AfterTestSubscriber;
            use Greenlight\Plugin\HarnessProvider;
            use Greenlight\Plugin\RetryDecider;
            use Greenlight\Plugin\TestContext;
            use AttachmentProbe\FailingClassResource;

            require_once __DIR__ . '/tests/AttachmentProbeTest.php';
            require_once __DIR__ . '/tests/TeardownAttachmentTest.php';

            final class AttachmentSubscriber implements AfterTestSubscriber
            {
                public function afterTest(TestContext $context, TestResult $result): TestResult
                {
                    if (!$result->outcome->isSuccessful()) {
                        $context->attachments->text('plugin.txt', 'plugin evidence');
                    }

                    return $result;
                }
            }

            final class AttachmentRetryDecider implements RetryDecider
            {
                public function shouldRetry(
                    RetryPolicy $policy,
                    TestResult $result,
                    int $attempt,
                    ?\Throwable $cause,
                ): bool {
                    if ($result->id->method === 'retryDeciderThrows') {
                        throw new \RuntimeException('retry decider failed');
                    }

                    return false;
                }
            }

            final class AttachmentHarness implements HarnessProvider
            {
                public function services(): array
                {
                    return [
                        new ServiceDefinition(
                            FailingClassResource::class,
                            Scope::PerClass,
                            static fn(): FailingClassResource => new FailingClassResource(),
                        ),
                    ];
                }
            }

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->plugins(
                    static fn(): AttachmentSubscriber => new AttachmentSubscriber(),
                    static fn(): AttachmentRetryDecider => new AttachmentRetryDecider(),
                    static fn(): AttachmentHarness => new AttachmentHarness(),
                )
                ->artifacts(fn(ArtifactBuilder $artifacts) => $artifacts
                    ->directory(__DIR__ . '/artifacts'));
            PHP);

        return $project;
    }
}
