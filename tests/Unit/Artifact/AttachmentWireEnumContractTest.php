<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\AttachmentKind;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class AttachmentWireEnumContractTest
{
    #[Test]
    public function attachmentEnumsKeepTheirPublishedWireValues(): void
    {
        Expect::value(\array_column(AttachmentKind::cases(), 'value', 'name'))
            ->because('attachment kinds MUST keep their published wire values')
            ->toBe([
                'Value' => 'value',
                'Text' => 'text',
                'Binary' => 'binary',
                'File' => 'file',
            ]);
        Expect::value(\array_column(AttachmentRetention::cases(), 'value', 'name'))
            ->because('attachment retention MUST keep its published wire values')
            ->toBe([
                'OnFailure' => 'on-failure',
                'Always' => 'always',
            ]);
    }
}
