<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Backend\PdfDiagnostics;

use Madj2k\AiAssistantPremium\Backend\PdfDiagnostics\PdfDiagnosticService;
use Madj2k\AiAssistantPremium\Backend\PdfDiagnostics\PdfLayoutDiagnosticsProvider;
use Madj2k\AiAssistantPremium\Backend\PdfDiagnostics\PdfSuspiciousOverlapDetector;
use Madj2k\AiAssistantPremium\Backend\PdfDiagnostics\PdfTextRegionMatcher;
use Madj2k\AiAssistantPremium\Backend\PdfDiagnostics\PdfVectorArtworkDetector;
use Madj2k\AiAssistantPremium\Indexing\Adapter\PdfTextNormalizer;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfDocumentExtractionService;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Rendering\PdfLayoutRenderer;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Preprocessing\PdfMarginArtifactDetector;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfPageLayoutAnalyzer;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfPageTextExtractor;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Font;

final class PdfDiagnosticServiceTest extends TestCase
{
    public function testLinksOriginalColumnObjectsInsteadOfMergedVisualRow(): void
    {
        $left = 'Left column sentence with enough characters to overlap the gutter';
        $right = 'Right column sentence';
        $visualization = $this->createVisualization(
            [
                [[1, 0, 0, 1, 20, 500], $left, 'F1', '10'],
                [[1, 0, 0, 1, 350, 500], $right, 'F1', '10'],
            ],
            $left . "\n\n" . $right,
        );

        self::assertCount(2, $visualization['regions']);
        self::assertSame($left, $visualization['regions'][0]['text']);
        self::assertSame($right, $visualization['regions'][1]['text']);
        self::assertSame(2, substr_count($visualization['annotatedText'], '<mark '));
    }

    public function testLinksLineEndHyphenFragmentsAndRepeatedText(): void
    {
        $visualization = $this->createVisualization(
            [
                [[1, 0, 0, 1, 20, 500], 'mallow fa-', 'F1', '10'],
                [[1, 0, 0, 1, 20, 485], 'mily', 'F1', '10'],
                [[1, 0, 0, 1, 20, 450], 'Repeated', 'F1', '10'],
                [[1, 0, 0, 1, 20, 435], 'Repeated', 'F1', '10'],
                [[1, 0, 0, 1, 20, 420], '-', 'F1', '10'],
            ],
            "mallow family\n\nRepeated Repeated",
        );

        self::assertCount(4, $visualization['regions']);
        self::assertSame(4, substr_count($visualization['annotatedText'], '<mark '));
        self::assertStringContainsString('>mallow fa</mark>', $visualization['annotatedText']);
    }

    public function testLinksRepeatedWordsToTheirVisualTextContext(): void
    {
        $visualization = $this->createVisualization(
            [
                [[1, 0, 0, 1, 20, 500], 'that', 'F1', '10'],
                [[1, 0, 0, 1, 50, 500], 'feel playful', 'F1', '10'],
                [[1, 0, 0, 1, 350, 500], 'concepts', 'F1', '10'],
                [[1, 0, 0, 1, 405, 500], 'that', 'F1', '10'],
                [[1, 0, 0, 1, 435, 500], 'deliver', 'F1', '10'],
            ],
            "that feel playful\n\nconcepts that deliver",
        );

        preg_match_all(
            '/data-region-id="([^"]+)"[^>]*>that<\/mark>/',
            $visualization['annotatedText'],
            $matches,
        );

        self::assertSame(
            ['page-1-region-1', 'page-1-region-4'],
            $matches[1],
        );
    }

    public function testGroupsAdjacentWordObjectsIntoOneDiagnosticLine(): void
    {
        $visualization = $this->createVisualization(
            [
                [[1, 0, 0, 1, 20, 500], 'One', 'F1', '10'],
                [[1, 0, 0, 1, 45, 500], 'two', 'F1', '10'],
                [[1, 0, 0, 1, 70, 500], 'three', 'F1', '10'],
            ],
            'One two three',
        );

        self::assertCount(3, $visualization['regions']);
        self::assertCount(1, $visualization['lineRegions']);
        self::assertSame('One two three', $visualization['lineRegions'][0]['text']);
        self::assertSame(0, $visualization['lineRegions'][0]['textStart']);
        self::assertSame(13, $visualization['lineRegions'][0]['textEnd']);
        self::assertSame(1, substr_count($visualization['annotatedLineText'], '<mark '));
    }

    public function testUsesEmbeddedFontMetricsAndHorizontalScaleForRegionWidth(): void
    {
        $font = $this->createStub(Font::class);
        $font->method('getDetails')->willReturn(['FirstChar' => 0, 'LastChar' => 255]);
        $font->method('calculateTextWidth')->willReturn(5000.0);
        $visualization = $this->createVisualization(
            [[[2, 0, 0, 2, 20, 500], 'Metric text', 'F1', '10']],
            'Metric text',
            ['F1' => $font],
        );

        // 5000 font units / 1000 * (font size 10 * horizontal scale 2)
        // plus 0.8 units of padding on either side, normalized to page width.
        self::assertEqualsWithDelta(
            (100.0 + 1.6) / 595.0 * 100.0,
            $visualization['regions'][0]['width'],
            0.001,
        );
    }

    public function testUsesBaselineAndVerticalMatrixScaleForRegionHeight(): void
    {
        $visualization = $this->createVisualization(
            [[[2, 0, 0, 3, 20, 500], 'Scaled text', 'F1', '10']],
            'Scaled text',
        );

        // Font size 10 with a vertical matrix scale of 3 results in a rendered
        // height of 30 units. The overlay covers ascender and descender around
        // the baseline without the former 25% line-height inflation.
        self::assertEqualsWithDelta(
            30.0 * 1.02 / 842.0 * 100.0,
            $visualization['regions'][0]['height'],
            0.001,
        );
        self::assertEqualsWithDelta(
            (842.0 - (500.0 + 30.0 * 0.82)) / 842.0 * 100.0,
            $visualization['regions'][0]['top'],
            0.001,
        );
    }

    public function testAdvancesConsecutiveTextRunsWithoutAnExplicitPositionReset(): void
    {
        $font = $this->createStub(Font::class);
        $font->method('getDetails')->willReturn(['FirstChar' => 0, 'LastChar' => 255]);
        $font->method('calculateTextWidth')->willReturn(1000.0);
        $visualization = $this->createVisualization(
            [
                [[10, 0, 0, 10, 20, 500], 'Regular ', 'F1', '1'],
                [[10, 0, 0, 10, 20, 500], 'bold ', 'F1', '1'],
                [[10, 0, 0, 10, 20, 500], 'regular', 'F1', '1'],
            ],
            'Regular bold regular',
            ['F1' => $font],
            [0 => false, 1 => true, 2 => true],
        );

        self::assertEqualsWithDelta(
            (20.0 - 0.8) / 595.0 * 100.0,
            $visualization['regions'][0]['left'],
            0.001,
        );
        self::assertEqualsWithDelta(
            (30.0 - 0.8) / 595.0 * 100.0,
            $visualization['regions'][1]['left'],
            0.001,
        );
        self::assertEqualsWithDelta(
            (40.0 - 0.8) / 595.0 * 100.0,
            $visualization['regions'][2]['left'],
            0.001,
        );
    }

    public function testKeepsExplicitlyRepositionedTextAtItsOriginalOrigin(): void
    {
        $font = $this->createStub(Font::class);
        $font->method('getDetails')->willReturn(['FirstChar' => 0, 'LastChar' => 255]);
        $font->method('calculateTextWidth')->willReturn(1000.0);
        $visualization = $this->createVisualization(
            [
                [[10, 0, 0, 10, 20, 500], 'Visible text', 'F1', '1'],
                [[10, 0, 0, 10, 20, 500], 'Overprint', 'F1', '1'],
            ],
            'Visible text Overprint',
            ['F1' => $font],
            [0 => false, 1 => false],
        );

        self::assertSame(
            $visualization['regions'][0]['left'],
            $visualization['regions'][1]['left'],
        );
    }

    /**
     * @param array<int, array<int, mixed>> $positionedText
     * @return array<string, mixed>
     */
    private function createVisualization(
        array $positionedText,
        string $text,
        array $fonts = [],
        array $continuations = [],
    ): array
    {
        $reader = new PdfPositionedTextReader();
        $analyzer = new PdfPageLayoutAnalyzer($reader, new PdfLayoutRenderer($reader));
        $normalizer = new PdfTextNormalizer();
        $subject = new PdfDiagnosticService(
            new PdfDocumentExtractionService(
                $normalizer,
                new PdfPageTextExtractor($analyzer),
                new PdfMarginArtifactDetector($reader),
            ),
            $normalizer,
            $reader,
            new PdfTextRegionMatcher(),
            new PdfSuspiciousOverlapDetector(),
            new PdfLayoutDiagnosticsProvider($reader),
            new PdfVectorArtworkDetector($reader),
        );
        $method = new \ReflectionMethod($subject, 'createVisualization');

        return $method->invoke(
            $subject,
            $positionedText,
            ['MediaBox' => [0, 0, 595, 842]],
            $text,
            1,
            [],
            $fonts,
            $continuations,
        );
    }
}
