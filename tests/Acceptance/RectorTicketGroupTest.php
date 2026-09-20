<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class RectorTicketGroupTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

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

        expect($probe->changed)
            ->because('the ticket attribute MUST be convertible')
            ->toBeTrue();
        expect($probe->code)
            ->because('the converted group MUST keep the ticket identifier')
            ->toContain("#[\Greenlight\Attribute\Group('GH-123')]");

        $run = $probe->runConvertedTests(['--group=GH-123']);

        expect($run->exitCode)
            ->because('the converted ticket group MUST select its test')
            ->toBe(0);
        expect($run->stdout)
            ->toContain('1 test, 1 passed')
            ->not()->toContain('2 tests');
    }
}
