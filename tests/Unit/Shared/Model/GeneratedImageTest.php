<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\GeneratedImage;
use PHPUnit\Framework\TestCase;

class GeneratedImageTest extends TestCase
{
    public function testConstructorAndProperties(): void
    {
        $image = new GeneratedImage('base64data', 'image/png', 'revised prompt');

        $this->assertSame('base64data', $image->data);
        $this->assertSame('image/png', $image->mimeType);
        $this->assertSame('revised prompt', $image->revisedPrompt);
    }

    public function testRevisedPromptDefaultsToNull(): void
    {
        $image = new GeneratedImage('data', 'image/jpeg');

        $this->assertNull($image->revisedPrompt);
    }

    public function testToAttachmentArray(): void
    {
        $image = new GeneratedImage('imgdata', 'image/webp');

        $this->assertSame([
            'mime_type' => 'image/webp',
            'data' => 'imgdata',
        ], $image->toAttachmentArray());
    }
}
