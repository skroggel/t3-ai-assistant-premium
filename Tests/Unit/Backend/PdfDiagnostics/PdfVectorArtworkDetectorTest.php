<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Backend\PdfDiagnostics;

use Madj2k\AiAssistantPremium\Backend\PdfDiagnostics\PdfVectorArtworkDetector;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;
use PHPUnit\Framework\TestCase;

final class PdfVectorArtworkDetectorTest extends TestCase
{
    public function testMarksSubstantialVectorArtworkInsideATextFreeBand(): void
    {
        $subject = new PdfVectorArtworkDetector(new PdfPositionedTextReader());
        $paths = [];
        for ($index = 0; $index < 16; $index++) {
            $x = 120 + ($index % 4) * 70;
            $y = 180 + intdiv($index, 4) * 45;
            $paths[] = sprintf('%d %d 35 24 re f', $x, $y);
        }

        $result = $subject->detect(
            [
                [[1, 0, 0, 1, 80, 700], 'Extractable heading', 'F1', '20'],
                [[1, 0, 0, 1, 80, 620], 'Extractable introduction', 'F1', '10'],
                [[1, 0, 0, 1, 80, 40], 'Footer', 'F1', '8'],
            ],
            ['MediaBox' => [0, 0, 600, 800]],
            implode("\n", $paths),
        );

        self::assertCount(1, $result);
        self::assertSame('vector-artwork', $result[0]['type']);
        self::assertSame(
            'LLL:EXT:ai_assistant_premium/Resources/Private/Language/locallang_pdf_diagnostics.xlf:artifact.vectorArtwork.label',
            $result[0]['label'],
        );
        self::assertTrue($result[0]['excluded']);
    }

    public function testIgnoresSmallDecorativeVectorCollection(): void
    {
        $subject = new PdfVectorArtworkDetector(new PdfPositionedTextReader());

        $result = $subject->detect(
            [[[1, 0, 0, 1, 80, 700], 'Heading', 'F1', '20']],
            ['MediaBox' => [0, 0, 600, 800]],
            '100 100 30 30 re f 150 100 30 30 re f',
        );

        self::assertSame([], $result);
    }

    public function testIgnoresOutlinedBrandingInTheOuterPageMargin(): void
    {
        $subject = new PdfVectorArtworkDetector(new PdfPositionedTextReader());
        $paths = [];
        for ($index = 0; $index < 20; $index++) {
            $x = 100 + ($index % 10) * 35;
            $paths[] = sprintf('%d 770 18 12 re f', $x);
        }

        $result = $subject->detect(
            [[[1, 0, 0, 1, 80, 600], 'Page content', 'F1', '10']],
            ['MediaBox' => [0, 0, 600, 800]],
            implode("\n", $paths),
        );

        self::assertSame([], $result);
    }
}
