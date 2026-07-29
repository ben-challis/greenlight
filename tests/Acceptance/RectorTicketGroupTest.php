<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\GreenlightCli;
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
            ->toBeTrue()
            ->and($probe->code)
            ->because('the converted group MUST keep the ticket identifier')
            ->toContain("#[\Greenlight\Attribute\Group('GH-123')]");

        \file_put_contents($probe->directory . '/greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/ProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1);

            PHP);

        $run = GreenlightCli::run(
            $probe->directory,
            ['run', '--group=GH-123', '--no-ansi'],
        );

        Expect::that($run->exitCode)
            ->because('the converted ticket group MUST select its test')
            ->toBe(0)
            ->and($run->stdout)
            ->toContain('1 test, 1 passed')
            ->not()
            ->toContain('2 tests');
    }
}
