<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactRunRecord;
use Greenlight\Execution\Artifact\RetentionPolicy;

use function Greenlight\expect;

final readonly class RetentionPolicyTest
{
    #[Test]
    public function limitsApplyAgeThenCountThenSizeToTheOldestRunFirst(): void
    {
        $policy = new RetentionPolicy(new ArtifactConfiguration(
            maxCompletedRuns: 2,
            maxCompletedRunAgeSeconds: 50,
            maxRetainedBytes: 15,
        ));

        $selection = $policy->select([
            $this->completed('run-sized', 70, 10),
            $this->completed('run-aged', 10, 10),
            $this->completed('run-counted', 60, 10),
            $this->completed('run-kept', 80, 10),
            $this->completed('run-counted-too', 60, 10),
        ], [], 100);

        expect($this->summary($selection))
            ->because('the policy MUST apply its limits in order to completion-time and run-ID order')
            ->toBe([
                ['run-aged', ['age']],
                ['run-counted', ['count']],
                ['run-counted-too', ['count']],
                ['run-sized', ['size']],
            ]);
    }

    #[Test]
    public function theProtectedRunAndFutureCompletionsAreNeverSelected(): void
    {
        $policy = new RetentionPolicy(new ArtifactConfiguration(maxCompletedRuns: 2, maxCompletedRunAgeSeconds: 1));

        $selection = $policy->select([
            $this->completed('run-current', 1, 1),
            $this->completed('run-future', 200, 1),
            $this->active('run-current-active', 1),
        ], ['run-current' => true], 100);

        expect($this->summary($selection))->toBe([['run-current-active', ['abandoned']]]);
        expect($policy->select([$this->active('run-current', 1)], ['run-current' => true], 100))
            ->because('the protected run MUST NOT be selected as an abandoned run')
            ->toBe([]);
    }

    #[Test]
    public function activeRecordsFollowCompletedRecordsInStartOrder(): void
    {
        $policy = new RetentionPolicy(new ArtifactConfiguration());

        $selection = $policy->select([
            $this->active('run-late', 30),
            $this->completed('run-completed', 10, 1),
            $this->active('run-early', 20),
        ], [], 100);

        expect($this->summary($selection))
            ->because('without limits, the policy MUST select only the supplied active records')
            ->toBe([['run-early', ['abandoned']], ['run-late', ['abandoned']]]);
    }

    /** @param non-empty-string $runId */
    private function completed(string $runId, int $completedAt, int $bytes): ArtifactRunRecord
    {
        return new ArtifactRunRecord($runId, 0, $completedAt, [], $bytes);
    }

    /** @param non-empty-string $runId */
    private function active(string $runId, int $startedAt): ArtifactRunRecord
    {
        return new ArtifactRunRecord($runId, $startedAt, $startedAt, [], 1, active: true);
    }

    /**
     * @param list<array{ArtifactRunRecord, non-empty-list<string>}> $selection
     * @return list<array{string, non-empty-list<string>}>
     */
    private function summary(array $selection): array
    {
        return \array_map(static fn(array $entry): array => [$entry[0]->runId, $entry[1]], $selection);
    }
}
