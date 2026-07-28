<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Artifact\AttachmentRetention;
use Greenlight\Expect\Expect;

final class AttachmentWireEnumContractTest
{
    #[Test]
    public function attachmentEnumsKeepTheirPublishedWireValues(): void
    {
        Expect::that(\array_column(AttachmentKind::cases(), 'value', 'name'))
            ->because('attachment kinds MUST keep their published wire values')
            ->toBe([
                'Value' => 'value',
                'Text' => 'text',
                'Binary' => 'binary',
                'File' => 'file',
            ])
            ->and(\array_column(AttachmentRetention::cases(), 'value', 'name'))
            ->because('attachment retention MUST keep its published wire values')
            ->toBe([
                'OnFailure' => 'on-failure',
                'Always' => 'always',
            ]);
    }
}
