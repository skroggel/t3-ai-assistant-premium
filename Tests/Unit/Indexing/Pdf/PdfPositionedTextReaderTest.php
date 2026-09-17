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
        self::assertEqualsWithDelta(
            217.32 + mb_strlen('Matrix-scaled text') * 11 * 0.58,
            $rows[0]['parts'][0]['xMax'],
            0.001,
        );
    }
}
