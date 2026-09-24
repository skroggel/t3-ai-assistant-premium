<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Indexing\Pdf;

use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfPositionedTextReader;
use PHPUnit\Framework\TestCase;

final class PdfPositionedTextReaderTest extends TestCase
{
    public function testKeepsNormallyDeclaredFontSizeWithUnitMatrixScale(): void
    {
        $rows = (new PdfPositionedTextReader())->read([
            [[1, 0, 0, 1, 20, 500], 'Normal text', 'F1', '11'],
        ]);

        self::assertSame(11.0, $rows[0]['parts'][0]['atoms'][0]['fontSize']);
        self::assertSame(11.0, $rows[0]['parts'][0]['atoms'][0]['horizontalScale']);
        self::assertSame(11.0, $rows[0]['parts'][0]['atoms'][0]['verticalScale']);
        self::assertEqualsWithDelta(
            20 + mb_strlen('Normal text') * 11 * 0.58,
            $rows[0]['parts'][0]['xMax'],
            0.001,
        );
    }

    public function testUsesTextMatrixScaleForUnitFontSizeEncoding(): void
    {
        $rows = (new PdfPositionedTextReader())->read([
            [[11, 0, 0, 11, 217.32, 762.88], 'Matrix-scaled text', 'F1', '1'],
        ]);

        self::assertSame(11.0, $rows[0]['parts'][0]['atoms'][0]['fontSize']);
        self::assertSame(11.0, $rows[0]['parts'][0]['atoms'][0]['horizontalScale']);
        self::assertSame(11.0, $rows[0]['parts'][0]['atoms'][0]['verticalScale']);
        self::assertEqualsWithDelta(
            217.32 + mb_strlen('Matrix-scaled text') * 11 * 0.58,
            $rows[0]['parts'][0]['xMax'],
            0.001,
        );
    }

    public function testKeepsDiagnosticHorizontalAndVerticalMatrixScalesSeparate(): void
    {
        $rows = (new PdfPositionedTextReader())->read([
            [[2, 0, 0, 3, 20, 500], 'Scaled text', 'F1', '10'],
        ]);

        $atom = $rows[0]['parts'][0]['atoms'][0];
        self::assertSame(10.0, $atom['fontSize']);
        self::assertSame(20.0, $atom['horizontalScale']);
        self::assertSame(30.0, $atom['verticalScale']);
    }

    public function testGroupsOpticallyAlignedTextWithDifferentFontSizes(): void
    {
        $rows = (new PdfPositionedTextReader())->read([
            [[1, 0, 0, 1, 199.58, 603.55], '03', 'F1', '20.04'],
            [[1, 0, 0, 1, 243.14, 607.03], 'Introduction', 'F2', '11.04'],
        ]);

        self::assertCount(1, $rows);
        self::assertSame(['03 Introduction'], array_column($rows[0]['parts'], 'text'));
    }
}
