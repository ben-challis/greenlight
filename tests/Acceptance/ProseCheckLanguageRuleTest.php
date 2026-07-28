<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\ProseCheckRuleProbe;

final readonly class ProseCheckLanguageRuleTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    #[DataRow(['semicolon', 'The worker stops; the orchestrator continues.', 'The worker stops. The orchestrator continues.'], 'semicolon')]
    #[DataRow(['contraction', "The worker doesn't stop.", 'The worker does not stop.'], 'contraction')]
    #[DataRow(['contraction', 'The worker doesn’t stop.', 'The worker does not stop.'], 'Unicode contraction')]
    #[DataRow(['contraction', "Let's start the worker.", 'Start the worker.'], 'additional contraction')]
    #[DataRow(['contraction', "Here's why that should've worked.", 'Here is why that should have worked.'], 'missing forms')]
    #[DataRow(['contraction', "There'll be capacity, so that'll work.", 'There will be capacity, so that will work.'], 'future forms')]
    #[DataRow([
        'sentence-length',
        'The orchestrator collects every selected test class from the configured directories and sends one complete assignment to each available worker before the test run starts in parallel.',
        'The orchestrator collects every selected test class from the configured directories and sends one complete assignment to each available worker before the test run starts.',
    ], 'sentence length')]
    #[DataRow([
        'paragraph-length',
        'A worker starts. It reads the configuration. It runs tests. It records results. It sends events. It releases resources. It stops.',
        'A worker starts. It reads the configuration. It runs tests. It records results. It sends events. It stops.',
    ], 'paragraph length')]
    public function blockingRulesRejectInvalidProseAndAcceptTheValidCounterpart(
        string $rule,
        string $invalid,
        string $valid,
    ): void {
        ProseCheckRuleProbe::assertBlocks($this->tempDirectory, $rule, $invalid, $valid);
    }
}
