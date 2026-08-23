<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\ProseCheckRuleProbe;

final readonly class ProseCheckSpellingRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function blockingRulesRejectInvalidProseAndAcceptValidCounterparts(): void
    {
        ProseCheckRuleProbe::assertBlocks($this->tempDirectory, [
            'colour' => [
                'rule' => 'british-spelling',
                'invalid' => 'The reporter uses a different colour.',
                'valid' => 'The reporter uses a different color.',
            ],
            'favour' => [
                'rule' => 'british-spelling',
                'invalid' => 'The runner favours one worker.',
                'valid' => 'The runner favors one worker.',
            ],
            'honour-and-labelled' => [
                'rule' => 'british-spelling',
                'invalid' => 'The runner honours a labelled test.',
                'valid' => 'The runner honors a labeled test.',
            ],
            'normalise' => [
                'rule' => 'british-spelling',
                'invalid' => 'The driver normalises the data.',
                'valid' => 'The driver normalizes the data.',
            ],
            'parameterise' => [
                'rule' => 'british-spelling',
                'invalid' => 'The runner parameterises tests.',
                'valid' => 'The runner parameterizes tests.',
            ],
            'deserialise' => [
                'rule' => 'british-spelling',
                'invalid' => 'The reporter deserialises the event.',
                'valid' => 'The reporter deserializes the event.',
            ],
            'fulfil' => [
                'rule' => 'british-spelling',
                'invalid' => 'The worker fulfils the request.',
                'valid' => 'The worker fulfills the request.',
            ],
            'other-spellings' => [
                'rule' => 'british-spelling',
                'invalid' => 'Organise the authorised customisation.',
                'valid' => 'Organize the authorized customization.',
            ],
        ]);
    }
}
