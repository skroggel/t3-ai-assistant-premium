<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Indexing\Pdf\Preprocessing;

use Madj2k\AiAssistantPremium\Indexing\Pdf\Preprocessing\PdfPositionedTextAligner;
use PHPUnit\Framework\TestCase;

final class PdfPositionedTextAlignerTest extends TestCase
{
    public function testAlignsShiftedDataTmTextWithNativeShowTextCommands(): void
    {
        $positionedText = [
            [[10, 0, 0, 10, 490, 223], 'Hibiscus', 'F1', '1'],
            [[10, 0, 0, 10, 495, 299], ' ', 'F1', '1'],
            [[10, 0, 0, 10, 497, 289], 'Relaxation', 'F1', '1'],
        ];
        $commands = [
            ['o' => 'TJ', 'c' => [
                ['t' => '(', 'o' => 'TJ', 'c' => 'Relax'],
                ['t' => 'n', 'o' => '', 'c' => '3'],
                ['t' => '(', 'o' => 'TJ', 'c' => 'ation'],
            ]],
            ['o' => 'Tj', 'c' => 'Immune'],
            ['o' => 'TJ', 'c' => [
                ['t' => '(', 'o' => 'TJ', 'c' => 'System'],
            ]],
        ];

        $result = (new PdfPositionedTextAligner())->align($positionedText, $commands);

        self::assertSame(['Relaxation', 'Immune', 'System'], array_column($result, 1));
        self::assertSame([10, 0, 0, 10, 490, 223], $result[0][0]);
    }

    public function testKeepsOriginalDataWhenCommandCountsDiffer(): void
    {
        $positionedText = [
            [[10, 0, 0, 10, 20, 30], 'Original', 'F1', '1'],
        ];

        $result = (new PdfPositionedTextAligner())->align($positionedText, []);

        self::assertSame($positionedText, $result);
    }

    public function testKeepsFontDecodedTextForInvalidUtf8CommandBytes(): void
    {
        $positionedText = [
            [[10, 0, 0, 10, 20, 30], 'Hibiscus “the beautiful flowering plant”', 'F1', '1'],
        ];
        $commands = [
            ['o' => 'Tj', 'c' => "Hibiscus \x93the beautiful flowering plant\x94"],
        ];

        $result = (new PdfPositionedTextAligner())->align($positionedText, $commands);

        self::assertSame('Hibiscus “the beautiful flowering plant”', $result[0][1]);
    }

    public function testKeepsFontDecodedTextInsteadOfUtf8Mojibake(): void
    {
        $positionedText = [
            [[10, 0, 0, 10, 20, 30], 'Döhler consumer study', 'F1', '1'],
        ];
        $commands = [
            ['o' => 'Tj', 'c' => 'DÃ¶hler consumer study'],
        ];

        $result = (new PdfPositionedTextAligner())->align($positionedText, $commands);

        self::assertSame('Döhler consumer study', $result[0][1]);
    }

    public function testKeepsDecodedTextInsteadOfHexadecimalOperandSource(): void
    {
        $positionedText = [
            [[10, 0, 0, 10, 20, 30], 'Döhler GmbH', 'F1', '1'],
        ];
        $commands = [
            ['o' => 'Tj', 'c' => '0027007C004B004F00480055'],
        ];

        $result = (new PdfPositionedTextAligner())->align($positionedText, $commands);

        self::assertSame('Döhler GmbH', $result[0][1]);
    }

    public function testKeepsDecodedSymbolInsteadOfShortHexadecimalOperandSource(): void
    {
        $positionedText = [
            [[10, 0, 0, 10, 20, 30], '–', 'F1', '1'],
            [[10, 0, 0, 10, 40, 30], '→', 'F1', '1'],
        ];
        $commands = [
            ['o' => 'Tj', 'c' => '00B1'],
            ['o' => 'Tj', 'c' => '013A'],
        ];

        $result = (new PdfPositionedTextAligner())->align($positionedText, $commands);

        self::assertSame(['–', '→'], array_column($result, 1));
    }
}
