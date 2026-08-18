<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorTicketGroupTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function ticketAttributesRemainSelectableGroups(): void
    {
        $probe = RectorProbe::convert(
            $this->tempDirectory,
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\Ticket;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                #[Ticket('GH-123')]
                public function testTicketedBehavior(): void
                {
                    $this->assertTrue(true);
                }

                public function testUngroupedBehavior(): void
                {
                    $this->assertTrue(true);
                }
            }

            PHP_WRAP,
            name: 'ticket-group',
        );

        Expect::that($probe->changed)
            ->because('the ticket attribute MUST be convertible')
            ->toBeTrue();
        Expect::that($probe->code)
            ->because('the converted group MUST keep the ticket identifier')
            ->toContain("#[\Greenlight\Attribute\Group('GH-123')]");

        $run = $probe->runConvertedTests(['--group=GH-123']);

        Expect::that($run->exitCode)
            ->because('the converted ticket group MUST select its test')
            ->toBe(0);
        Expect::that($run->stdout)
            ->toContain('1 test, 1 passed')
            ->not()
            ->toContain('2 tests');
    }
}
