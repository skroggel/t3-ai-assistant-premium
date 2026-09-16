<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Indexing\Pdf;

use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfPageLayoutAnalyzer;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfPageTextExtractor;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Page;

final class PdfPageTextExtractorTest extends TestCase
{
    public function testUsesPositionedTextForDetectedColumns(): void
    {
        $positionedText = [];
        for ($row = 0; $row < 5; $row++) {
            $y = 500.0 - $row * 14;
            $positionedText[] = [[1, 0, 0, 1, 20, $y], 'Left column sentence ' . $row, 'F1', '10'];
            $positionedText[] = [[1, 0, 0, 1, 350, $y], 'Right column sentence ' . $row, 'F1', '10'];
        }

        $page = $this->createStub(Page::class);
        $page->method('getText')->willReturn('native interleaved text');
        $page->method('getDataTm')->willReturn($positionedText);
        $subject = new PdfPageTextExtractor(new PdfPageLayoutAnalyzer());

        $result = $subject->extract($page);

        self::assertSame('positioned', $result->strategy);
        self::assertSame('columns', $result->layoutType);
        self::assertStringContainsString('Left column sentence 4', $result->text);
    }

    public function testKeepsNativeTextForPlainPages(): void
    {
        $page = $this->createStub(Page::class);
        $page->method('getText')->willReturn('Native paragraph');
        $page->method('getDataTm')->willReturn([
            [[1, 0, 0, 1, 20, 500], 'Positioned paragraph', 'F1', '10'],
        ]);
        $subject = new PdfPageTextExtractor(new PdfPageLayoutAnalyzer());

        $result = $subject->extract($page);

        self::assertSame('native', $result->strategy);
        self::assertSame('Native paragraph', $result->text);
    }
}
