<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Indexing\Pdf;

use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfMarginArtifactDetector;
use PHPUnit\Framework\TestCase;

final class PdfMarginArtifactDetectorTest extends TestCase
{
    public function testExcludesAlternatingFooterAndPageNumberSequence(): void
    {
        $pages = [];
        for ($pageNumber = 1; $pageNumber <= 6; $pageNumber++) {
            $positionedText = [$this->entry(80, 500, 'Meaningful body text')];
            if ($pageNumber === 1) {
                $positionedText[] = $this->entry(500, 70, '2024');
            } else {
                $positionedText[] = $this->entry(295, 29, (string)$pageNumber);
                $positionedText[] = $this->entry(80, 55, 'Unique footnote ' . $pageNumber);
            }
            if ($pageNumber % 2 === 0) {
                $positionedText[] = $this->entry(42, 29, 'Cocoa Alternatives');
            }
            $pages[] = [
                'positionedText' => $positionedText,
                'details' => ['MediaBox' => [0, 0, 595, 842]],
            ];
        }

        $result = (new PdfMarginArtifactDetector())->analyze($pages);

        self::assertSame([], $result[0]['artifacts']);
        self::assertContains('2024', array_column($result[0]['positionedText'], 1));
        self::assertSame(
            ['Meaningful body text', 'Unique footnote 2'],
            array_column($result[1]['positionedText'], 1),
        );
        self::assertSame('footer', $result[1]['artifacts'][0]['type']);
        self::assertTrue($result[1]['artifacts'][0]['excluded']);
        self::assertStringContainsString('Cocoa Alternatives', $result[1]['artifacts'][0]['text']);
        self::assertStringContainsString('2', $result[1]['artifacts'][0]['text']);
        self::assertSame(
            ['Meaningful body text', 'Unique footnote 3'],
            array_column($result[2]['positionedText'], 1),
        );
    }

    public function testMarksRepeatedTopMarginTextAsHeader(): void
    {
        $pages = [];
        for ($pageNumber = 1; $pageNumber <= 4; $pageNumber++) {
            $pages[] = [
                'positionedText' => [
                    $this->entry(40, 820, 'Repeated document title'),
                    $this->entry(300, 817, 'Variable header ' . $pageNumber),
                    $this->entry(40, 500, 'Page body ' . $pageNumber),
                ],
                'details' => ['MediaBox' => [0, 0, 595, 842]],
            ];
        }

        $result = (new PdfMarginArtifactDetector())->analyze($pages);

        self::assertSame('header', $result[0]['artifacts'][0]['type']);
        self::assertSame(['Page body 1'], array_column($result[0]['positionedText'], 1));
        self::assertStringContainsString('Variable header 1', $result[0]['artifacts'][0]['text']);
    }

    public function testUsesPageNumberAsFooterAnchorForSinglePageDocument(): void
    {
        $result = (new PdfMarginArtifactDetector())->analyze([[
            'positionedText' => [
                $this->entry(40, 500, 'Meaningful body text'),
                $this->entry(40, 29, 'Company contact details'),
                $this->entry(295, 29, '1'),
            ],
            'details' => ['MediaBox' => [0, 0, 595, 842]],
        ]]);

        self::assertSame(['Meaningful body text'], array_column($result[0]['positionedText'], 1));
        self::assertSame('footer', $result[0]['artifacts'][0]['type']);
        self::assertStringContainsString('Company contact details', $result[0]['artifacts'][0]['text']);
    }

    /** @return array<int, mixed> */
    private function entry(float $x, float $y, string $text): array
    {
        return [[1, 0, 0, 1, $x, $y], $text, 'F1', '10'];
    }
}
