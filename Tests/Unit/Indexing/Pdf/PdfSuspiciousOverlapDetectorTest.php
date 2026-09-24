<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Indexing\Pdf;

use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfSuspiciousOverlapDetector;
use PHPUnit\Framework\TestCase;

final class PdfSuspiciousOverlapDetectorTest extends TestCase
{
    public function testReportsDifferentTextAtNearlyIdenticalCoordinates(): void
    {
        $warnings = (new PdfSuspiciousOverlapDetector())->detect([
            $this->entry(29.2, 622.13, 'Juice Drinks'),
            $this->entry(30.1, 622.25, 'Energy Drinks'),
        ]);

        self::assertCount(1, $warnings);
        self::assertSame('Juice Drinks', $warnings[0]['firstText']);
        self::assertSame('Energy Drinks', $warnings[0]['secondText']);
        self::assertGreaterThan(90.0, $warnings[0]['overlap']);
    }

    public function testIgnoresAdjacentAndIdenticalTextObjects(): void
    {
        $warnings = (new PdfSuspiciousOverlapDetector())->detect([
            $this->entry(20, 500, 'Left'),
            $this->entry(200, 500, 'Right'),
            $this->entry(20, 500, 'Left'),
        ]);

        self::assertSame([], $warnings);
    }

    public function testIgnoresParserCoordinatesForOneContinuousStyledTextRun(): void
    {
        $warnings = (new PdfSuspiciousOverlapDetector())->detect(
            [
                $this->entry(20, 500, 'Regular text'),
                $this->entry(20, 500, 'bold text'),
                $this->entry(20, 500, 'regular again'),
            ],
            [0 => false, 1 => true, 2 => true],
        );

        self::assertSame([], $warnings);
    }

    /** @return array<int, mixed> */
    private function entry(float $x, float $y, string $text): array
    {
        return [[1, 0, 0, 1, $x, $y], $text, 'F1', '11.04'];
    }
}
