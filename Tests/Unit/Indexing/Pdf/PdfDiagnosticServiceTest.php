<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Indexing\Pdf;

use Madj2k\AiAssistantPremium\Indexing\Adapter\PdfTextNormalizer;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfDiagnosticService;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfLayoutRenderer;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfMarginArtifactDetector;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfPageLayoutAnalyzer;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfPageTextExtractor;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfPositionedTextReader;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfSuspiciousOverlapDetector;
use PHPUnit\Framework\TestCase;

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

    /**
     * @param array<int, array<int, mixed>> $positionedText
     * @return array<string, mixed>
     */
    private function createVisualization(array $positionedText, string $text): array
    {
        $reader = new PdfPositionedTextReader();
        $analyzer = new PdfPageLayoutAnalyzer($reader, new PdfLayoutRenderer($reader));
        $subject = new PdfDiagnosticService(
            new PdfPageTextExtractor($analyzer),
            new PdfTextNormalizer(),
            $reader,
            new PdfSuspiciousOverlapDetector(),
            $analyzer,
            new PdfMarginArtifactDetector($reader),
        );
        $method = new \ReflectionMethod($subject, 'createVisualization');

        return $method->invoke(
            $subject,
            $positionedText,
            ['MediaBox' => [0, 0, 595, 842]],
            $text,
            1,
            [],
        );
    }
}
