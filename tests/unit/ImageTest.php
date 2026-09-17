<?php

use CropTool\Image;
use PHPUnit\Framework\TestCase;

class ImageTest extends TestCase
{
    public function testRotationFailureExplainsWhatToDoInstead()
    {
        $message = Image::imagickFailureMessage(15000, 12000, 0.3, 'Unspecified error');

        $this->assertStringContainsString('15000 × 12000 px', $message);
        $this->assertStringContainsString('Free rotation is not available', $message);
        $this->assertStringContainsString('then rotate the result in a second step', $message);

        // "Unspecified error" says nothing to the person cropping.
        $this->assertStringNotContainsString('Unspecified error', $message);
    }

    public function testNinetyDegreeRotationIsAlsoReportedAsRotation()
    {
        $message = Image::imagickFailureMessage(8368, 5712, 90, 'Unspecified error');

        $this->assertStringContainsString('8368 × 5712 px', $message);
        $this->assertStringContainsString('Free rotation is not available', $message);
    }

    public function testFailureWithoutRotationKeepsTheImageMagickDetail()
    {
        $message = Image::imagickFailureMessage(800, 600, 0, 'no decode delegate for this image format');

        $this->assertStringContainsString('800 × 600 px', $message);
        $this->assertStringContainsString('no decode delegate for this image format', $message);
    }

    public function testUnknownDimensionsAreNamedRatherThanPrintedAsZero()
    {
        $message = Image::imagickFailureMessage(null, null, 0.5);

        $this->assertStringContainsString('unknown dimensions', $message);
        $this->assertStringNotContainsString('0 × 0', $message);
    }
}
