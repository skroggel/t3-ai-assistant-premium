<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Indexing\Pdf;

use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfPageLayoutAnalyzer;
use PHPUnit\Framework\TestCase;

final class PdfPageLayoutAnalyzerTest extends TestCase
{
    public function testReordersParallelProseColumnsColumnByColumn(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [];
        for ($row = 0; $row < 5; $row++) {
            $y = 500.0 - $row * 14;
            $data[] = $this->entry(20, $y, 'Left column sentence number ' . ($row + 1));
            $data[] = $this->entry(350, $y, 'Right column sentence number ' . ($row + 1));
        }

        $result = $subject->analyze($data);

        self::assertSame('columns', $result->layoutType);
        self::assertSame(2, $result->columnCount);
        self::assertLessThan(
            strpos($result->text, 'Right column sentence number 1'),
            strpos($result->text, 'Left column sentence number 5'),
        );
    }

    public function testKeepsGeometricTableRowsTogetherWithoutHeaderKeywords(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [];
        foreach ([
            ['Alpha', 'X-17', '4 %', 'North'],
            ['Beta', 'Y-23', '8 %', 'South'],
            ['Gamma', 'Z-42', '12 %', 'West'],
            ['Delta', 'Q-51', '16 %', 'East'],
        ] as $rowIndex => $cells) {
            $y = 500.0 - $rowIndex * 18;
            foreach ([20, 180, 300, 410] as $cellIndex => $x) {
                $data[] = $this->entry($x, $y, $cells[$cellIndex]);
            }
        }

        $result = $subject->analyze($data);

        self::assertSame('table', $result->layoutType);
        self::assertSame(4, $result->tableRowCount);
        self::assertStringContainsString('Beta | Y-23 | 8 % | South', $result->text);
    }

    public function testDeduplicatesOverprintedGlyphRuns(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $entry = $this->entry(20, 500, 'Visible once');

        $result = $subject->analyze([$entry, $entry]);

        self::assertSame('Visible once', $result->text);
    }

    public function testIgnoresRotatedDecorativeText(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $horizontal = $this->entry(20, 500, 'Readable content');
        $rotated = [[0, 1, -1, 0, 500, 100], 'Decoration', 'F1', '10'];

        $result = $subject->analyze([$horizontal, $rotated]);

        self::assertSame('Readable content', $result->text);
    }

    /** @return array<int, mixed> */
    private function entry(float $x, float $y, string $text): array
    {
        return [[1, 0, 0, 1, $x, $y], $text, 'F1', '10'];
    }
}
