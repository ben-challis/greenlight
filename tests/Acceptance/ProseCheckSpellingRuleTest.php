<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\ProseCheckRuleProbe;

final readonly class ProseCheckSpellingRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    #[DataRow(['british-spelling', 'The reporter uses a different colour.', 'The reporter uses a different color.'], 'colour')]
    #[DataRow(['british-spelling', 'The runner favours one worker.', 'The runner favors one worker.'], 'favour')]
    #[DataRow(['british-spelling', 'The runner honours a labelled test.', 'The runner honors a labeled test.'], 'honour and labelled')]
    #[DataRow(['british-spelling', 'The driver normalises the data.', 'The driver normalizes the data.'], 'normalise')]
    #[DataRow(['british-spelling', 'The runner parameterises tests.', 'The runner parameterizes tests.'], 'parameterise')]
    #[DataRow(['british-spelling', 'The reporter deserialises the event.', 'The reporter deserializes the event.'], 'deserialise')]
    #[DataRow(['british-spelling', 'The worker fulfils the request.', 'The worker fulfills the request.'], 'fulfil')]
    #[DataRow([
        'british-spelling',
        'Organise the authorised customisation.',
        'Organize the authorized customization.',
    ], 'other spellings')]
    public function blockingRulesRejectInvalidProseAndAcceptTheValidCounterpart(
        string $rule,
        string $invalid,
        string $valid,
    ): void {
        ProseCheckRuleProbe::assertBlocks($this->tempDirectory, $rule, $invalid, $valid);
    }
}
