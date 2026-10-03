<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Indexing\Adapter;

use Madj2k\AiAssistantPremium\Indexing\Adapter\PdfTextNormalizer;
use PHPUnit\Framework\TestCase;

final class PdfTextNormalizerTest extends TestCase
{
    public function testNormalizesCommonPdfLigatures(): void
    {
        $subject = new PdfTextNormalizer();

        self::assertSame(
            'office flavour efficient',
            $subject->normalize("o\u{FB03}ce \u{FB02}avour e\u{FB03}cient"),
        );
    }

    public function testPreservesRowsWithSeveralColumns(): void
    {
        $subject = new PdfTextNormalizer();

        self::assertSame(
            "Alpha   X-17   4 %\nBeta    Y-23   8 %",
            $subject->normalize("Alpha   X-17   4 %\nBeta    Y-23   8 %"),
        );
    }
}
