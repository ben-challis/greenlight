<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\ProseCheckRuleProbe;

final readonly class ProseCheckLanguageRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function blockingRulesRejectInvalidProseAndAcceptValidCounterparts(): void
    {
        ProseCheckRuleProbe::assertBlocks($this->tempDirectory, [
            'semicolon' => [
                'rule' => 'semicolon',
                'invalid' => 'The worker stops; the orchestrator continues.',
                'valid' => 'The worker stops. The orchestrator continues.',
            ],
            'contraction' => [
                'rule' => 'contraction',
                'invalid' => "The worker doesn't stop.",
                'valid' => 'The worker does not stop.',
            ],
            'unicode-contraction' => [
                'rule' => 'contraction',
                'invalid' => 'The worker doesn’t stop.',
                'valid' => 'The worker does not stop.',
            ],
            'additional-contraction' => [
                'rule' => 'contraction',
                'invalid' => "Let's start the worker.",
                'valid' => 'Start the worker.',
            ],
            'missing-forms' => [
                'rule' => 'contraction',
                'invalid' => "Here's why that should've worked.",
                'valid' => 'Here is why that should have worked.',
            ],
            'future-forms' => [
                'rule' => 'contraction',
                'invalid' => "There'll be capacity, so that'll work.",
                'valid' => 'There will be capacity, so that will work.',
            ],
            'sentence-length' => [
                'rule' => 'sentence-length',
                'invalid' => 'The orchestrator collects every selected test class from the configured directories and sends one complete assignment to each available worker before the test run starts in parallel.',
                'valid' => 'The orchestrator collects every selected test class from the configured directories and sends one complete assignment to each available worker before the test run starts.',
            ],
            'paragraph-length' => [
                'rule' => 'paragraph-length',
                'invalid' => 'A worker starts. It reads the configuration. It runs tests. It records results. It sends events. It releases resources. It stops.',
                'valid' => 'A worker starts. It reads the configuration. It runs tests. It records results. It sends events. It stops.',
            ],
        ]);
    }
}
