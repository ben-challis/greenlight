<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;

final readonly class AttachmentMediaTypeTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function fileAttachmentsUseTheBinaryMediaTypeWithoutFileinfo(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'attachment-media-type');
        $project->writeFile('tests/AttachmentMediaTypeProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace AttachmentMediaTypeProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Core\Artifact\AttachmentRetention;
            use Greenlight\Core\Artifact\Attachments;
            use Greenlight\Expect\Expect;

            final readonly class AttachmentMediaTypeProbeTest
            {
                public function __construct(private Attachments $attachments) {}

                #[Test]
                public function attachesAFile(): void
                {
                    $source = __DIR__ . '/payload.bin';
                    file_put_contents($source, "payload\n");
                    $this->attachments->file(
                        'payload.bin',
                        $source,
                        retention: AttachmentRetention::Always,
                    );

                    Expect::that(true)->toBeTrue();
                }
            }
            PHP);
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\ArtifactBuilder;
            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/AttachmentMediaTypeProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->artifacts(fn(ArtifactBuilder $artifacts) => $artifacts
                    ->directory(__DIR__ . '/artifacts'));
            PHP);

        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--reporter=jsonl'],
            phpArguments: ['-d', 'disable_functions=finfo_open'],
        );
        $finished = null;

        foreach (JsonlEvents::from($result) as $event) {
            if ($event instanceof TestFinished) {
                $finished = $event->result;
            }
        }

        Expect::that($result->exitCode)
            ->because('file attachments work without the optional fileinfo function')
            ->toBe(0);
        Expect::that($finished)
            ->not()
            ->toBeNull();
        Expect::that($finished->attachments)
            ->toHaveCount(1);
        Expect::that($finished->attachments[0]->mediaType)
            ->toBe('application/octet-stream');
    }
}
