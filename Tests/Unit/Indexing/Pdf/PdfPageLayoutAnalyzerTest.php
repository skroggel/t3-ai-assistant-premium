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

    public function testKeepsFullWidthHeaderBeforeAsynchronousColumns(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [$this->entry(20, 580, str_repeat('Full width heading ', 5))];
        for ($row = 0; $row < 5; $row++) {
            $data[] = $this->entry(20, 500 - $row * 14, 'Left prose line ' . ($row + 1));
            $data[] = $this->entry(350, 496 - $row * 14, 'Right prose line ' . ($row + 1));
        }

        $result = $subject->analyze($data);

        self::assertSame('mixed', $result->layoutType);
        self::assertLessThan(strpos($result->text, 'Left prose line 1'), strpos($result->text, 'Full width heading'));
        self::assertLessThan(strpos($result->text, 'Right prose line 1'), strpos($result->text, 'Left prose line 5'));

        $regions = $subject->diagnoseRegions($data);
        self::assertSame('full-width', $regions[0]['type']);
        self::assertSame('columns', $regions[1]['type']);
        self::assertSame(2, $regions[1]['columnCount']);
    }

    public function testGroupsContinuousSingleColumnRowsIntoLayoutBands(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [
            $this->entry(20, 580, 'Introduction'),
            $this->entry(20, 530, 'First paragraph line with enough text to fill the content area'),
            $this->entry(20, 516, 'Second paragraph line with enough text to fill the content area'),
            $this->entry(20, 502, 'Third paragraph line with enough text to fill the content area'),
            $this->entry(20, 450, 'Highlighted closing statement'),
            $this->entry(20, 436, 'Second highlighted closing line'),
        ];

        $regions = $subject->diagnoseRegions($data);

        self::assertCount(3, $regions);
        self::assertSame(['full-width', 'full-width', 'full-width'], array_column($regions, 'type'));
    }

    public function testGroupsAdjacentFullWidthHeadingRowsBeforeColumns(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [
            $this->entry(20, 590, str_repeat('First heading line ', 5)),
            $this->entry(20, 574, str_repeat('Second heading line ', 5)),
        ];
        for ($row = 0; $row < 5; $row++) {
            $data[] = $this->entry(20, 520 - $row * 14, 'Left prose line ' . ($row + 1));
            $data[] = $this->entry(350, 516 - $row * 14, 'Right prose line ' . ($row + 1));
        }

        $regions = $subject->diagnoseRegions($data);
        $analysis = $subject->analyze($data);

        self::assertCount(2, $regions);
        self::assertSame('full-width', $regions[0]['type']);
        self::assertSame('columns', $regions[1]['type']);
        self::assertSame('mixed', $analysis->layoutType);
        self::assertLessThan(strpos($analysis->text, 'Second heading line'), strpos($analysis->text, 'First heading line'));
        self::assertLessThan(strpos($analysis->text, 'Left prose line 1'), strpos($analysis->text, 'Second heading line'));
        self::assertLessThan(strpos($analysis->text, 'Right prose line 1'), strpos($analysis->text, 'Left prose line 5'));
    }

    public function testDetectsShortPairedCaptionBandsAsTwoColumnBlocks(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [
            $this->entry(20, 600, str_repeat('Full width heading ', 5)),
            $this->entry(20, 560, str_repeat('Full width introduction ', 4)),
            $this->entry(20, 450, 'Left first caption line'),
            $this->entry(350, 450, 'Right first caption line'),
            $this->entry(20, 436, 'Left first continuation'),
            $this->entry(350, 436, 'Right first continuation'),
            $this->entry(20, 422, 'Left first closing line'),
            $this->entry(350, 422, 'Right first closing line'),
            $this->entry(20, 300, 'Left second caption line'),
            $this->entry(350, 300, 'Right second caption line'),
            $this->entry(20, 286, 'Left second continuation'),
            $this->entry(350, 286, 'Right second continuation'),
            $this->entry(20, 272, 'Left second closing line'),
            $this->entry(350, 272, 'Right second closing line'),
            $this->entry(20, 130, 'First of three blocks'),
            $this->entry(220, 130, 'Second of three blocks'),
            $this->entry(420, 130, 'Third of three blocks'),
        ];

        $regions = $subject->diagnoseRegions($data);
        $analysis = $subject->analyze($data);

        self::assertSame(
            ['full-width', 'column-blocks', 'column-blocks', 'full-width'],
            array_column($regions, 'type'),
        );
        self::assertSame('mixed', $analysis->layoutType);
        self::assertLessThan(
            strpos($analysis->text, 'Right first caption line'),
            strpos($analysis->text, 'Left first continuation'),
        );
        self::assertLessThan(
            strpos($analysis->text, 'Right second caption line'),
            strpos($analysis->text, 'Left second continuation'),
        );
    }

    public function testReadsVerticallyOffsetParallelBlocksSideBySide(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [
            $this->entry(20, 550, 'Left callout first line'),
            $this->entry(350, 547, 'Right paragraph first line'),
            $this->entry(350, 533, 'Right paragraph second line'),
            $this->entry(20, 530, 'Left callout second line'),
            $this->entry(350, 519, 'Right paragraph third line'),
            $this->entry(20, 510, 'Left callout third line'),
            $this->entry(350, 505, 'Right paragraph fourth line'),
            // A distant paired band supplies stable page-wide column evidence
            // without changing the short offset region above.
            $this->entry(20, 300, 'Left lower caption'),
            $this->entry(350, 300, 'Right lower caption'),
        ];

        $analysis = $subject->analyze($data);
        $regions = $subject->diagnoseRegions($data);

        self::assertSame('columns', $analysis->layoutType);
        self::assertSame('column-blocks', $regions[0]['type']);
        self::assertLessThan(
            strpos($analysis->text, 'Right paragraph first line'),
            strpos($analysis->text, 'Left callout third line'),
        );
        self::assertLessThan(
            strpos($analysis->text, 'Right paragraph fourth line'),
            strpos($analysis->text, 'Right paragraph first line'),
        );
    }

    public function testKeepsLongerColumnTailWithItsEstablishedColumn(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [];
        for ($row = 0; $row < 5; $row++) {
            $y = 500.0 - $row * 14;
            $data[] = $this->entry(20, $y, 'Left column line ' . ($row + 1));
            $data[] = $this->entry(350, $y - 3, 'Right column line ' . ($row + 1));
        }
        $data[] = $this->entry(
            20,
            426,
            'Left continuation whose estimated width reaches across the calculated split',
        );
        $data[] = $this->entry(
            20,
            412,
            'Second left continuation remains part of the same unequal column',
        );

        $result = $subject->analyze($data);
        $regions = $subject->diagnoseRegions($data);

        self::assertSame('columns', $result->layoutType);
        self::assertCount(1, $regions);
        self::assertSame('columns', $regions[0]['type']);
        self::assertLessThan(
            strpos($result->text, 'Right column line 1'),
            strpos($result->text, 'Second left continuation'),
        );
    }

    public function testKeepsVerticallyAsymmetricColumnsInColumnReadingOrder(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [
            $this->entry(350, 680, 'Right callout heading'),
            $this->entry(350, 648, 'Right callout first line'),
            $this->entry(350, 632, 'Right callout second line'),
            $this->entry(20, 566, 'Left main heading'),
            $this->entry(350, 566, 'Right callout third line'),
            $this->entry(20, 530, 'Left introduction whose estimated width crosses the split'),
            $this->entry(350, 526, 'Right callout fourth line'),
            $this->entry(20, 506, 'Left second line whose estimated width also crosses the split'),
            $this->entry(350, 490, 'Right callout fifth line'),
            $this->entry(20, 476, 'Left introduction closing line'),
            $this->entry(350, 474, 'Right callout closing line'),
            $this->entry(20, 450, 'Left prose first line'),
            $this->entry(350, 450, 'Right final line'),
            $this->entry(20, 434, 'Left prose second line'),
            $this->entry(350, 434, 'Right final continuation'),
            $this->entry(20, 406, 'Left continuation after the short column ended'),
            $this->entry(20, 390, 'Left final continuation'),
        ];

        $result = $subject->analyze($data);
        $regions = $subject->diagnoseRegions($data);

        self::assertSame('columns', $result->layoutType);
        self::assertCount(1, $regions);
        self::assertSame('columns', $regions[0]['type']);
        self::assertLessThan(
            strpos($result->text, 'Right callout heading'),
            strpos($result->text, 'Left final continuation'),
        );
        self::assertLessThan(
            strpos($result->text, 'Right final continuation'),
            strpos($result->text, 'Right callout heading'),
        );
    }

    public function testSeparatesBrochureProseCardsAndProductColumns(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [
            $this->entry(20, 556, 'Page title'),
            $this->entry(20, 527, 'A subtitle spanning the content area with sufficient text for the page'),
        ];
        for ($row = 0; $row < 6; $row++) {
            $data[] = $this->entry(20, 500 - $row * 14, 'Left prose line ' . ($row + 1));
            $data[] = $this->entry(320, 500 - $row * 14, 'Right prose line ' . ($row + 1));
        }
        $data[] = $this->entry(20, 385, 'Benefits heading');
        foreach ([
            360 => ['01', '02', '03'],
            346 => ['First card line one', 'Second card line one', 'Third card line one'],
            332 => ['First card line two', 'Second card line two', 'Third card line two'],
            318 => ['First card line three', 'Second card line three', 'Third card line three'],
            304 => ['First card line four', 'Second card line four', 'Third card closing'],
            290 => ['First card line five', 'Second card closing', null],
            276 => ['First card closing', null, null],
        ] as $y => $texts) {
            foreach ([30, 220, 410] as $column => $x) {
                if ($texts[$column] !== null) {
                    $data[] = $this->entry($x, $y, $texts[$column]);
                }
            }
        }
        $data[] = $this->entry(20, 220, 'Left product heading');
        $data[] = $this->entry(320, 220, 'Right solution heading');
        for ($row = 0; $row < 4; $row++) {
            $data[] = $this->entry(20, 200 - $row * 14, 'Left product line ' . ($row + 1));
            $data[] = $this->entry(320, 200 - $row * 14, 'Right solution line ' . ($row + 1));
        }

        $analysis = $subject->analyze($data);
        $regions = $subject->diagnoseRegions($data);

        self::assertSame('mixed', $analysis->layoutType);
        self::assertContains('multi-column-blocks', array_column($regions, 'type'));
        self::assertLessThan(strpos($analysis->text, 'Benefits heading'), strpos($analysis->text, 'Right prose line 6'));
        self::assertLessThan(strpos($analysis->text, 'Second card line one'), strpos($analysis->text, 'First card closing'));
        self::assertLessThan(strpos($analysis->text, 'Third card line one'), strpos($analysis->text, 'Second card closing'));
        self::assertLessThan(strpos($analysis->text, 'Left product heading'), strpos($analysis->text, 'Third card closing'));
        self::assertLessThan(strpos($analysis->text, 'Right solution heading'), strpos($analysis->text, 'Left product line 4'));
    }

    public function testReadsEqualHeightCardsByColumnWhenHeadingsAreDetached(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [];
        foreach ([
            350 => ['Card one', 'Card two', 'Card three'],
            322 => ['First body one', 'Second body one', 'Third body one'],
            308 => ['First body two', 'Second body two', 'Third body two'],
            294 => ['First body three', 'Second body three', 'Third body three'],
            280 => ['First closing', 'Second closing', 'Third closing'],
        ] as $y => $texts) {
            foreach ([30, 220, 410] as $column => $x) {
                $data[] = $this->entry($x, $y, $texts[$column]);
            }
        }

        $analysis = $subject->analyze($data);
        $regions = $subject->diagnoseRegions($data);

        self::assertSame('multi-column-blocks', $regions[0]['type']);
        self::assertSame(3, $regions[0]['columnCount']);
        self::assertLessThan(strpos($analysis->text, 'Card two'), strpos($analysis->text, 'First closing'));
        self::assertLessThan(strpos($analysis->text, 'Card three'), strpos($analysis->text, 'Second closing'));
    }

    public function testDoesNotTurnFragmentedTwoColumnProseIntoThreeColumns(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [];
        for ($row = 0; $row < 5; $row++) {
            $y = 500.0 - $row * 14;
            $data[] = $this->entry(30, $y, 'Left prose');
            $data[] = $this->entry(170, $y, 'middle prose');
            $data[] = $this->entry(350, $y, 'right prose fragment');
        }
        $data[] = $this->entry(30, 420, 'Left closing');
        $data[] = $this->entry(170, 406, 'Middle closing');

        $regions = $subject->diagnoseRegions($data);

        self::assertNotContains('multi-column-blocks', array_column($regions, 'type'));
    }

    public function testKeepsFragmentedFullWidthSubtitleAheadOfTwoColumnProse(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [
            $this->entry(20, 560, 'Section title'),
            $this->entry(20, 532, 'Natural body booster for alcohol'),
            $this->entry(210, 532, '-free and low'),
            $this->entry(290, 532, '-ABV beers'),
        ];
        for ($row = 0; $row < 6; $row++) {
            $y = 503.0 - $row * 14;
            $data[] = $this->entry(20, $y, 'Left prose line ' . ($row + 1));
            $data[] = $this->entry(350, $y, 'Right prose line ' . ($row + 1));
        }

        $analysis = $subject->analyze($data);
        $regions = $subject->diagnoseRegions($data);

        self::assertNotContains('multi-column-blocks', array_column($regions, 'type'));
        self::assertLessThan(strpos($analysis->text, 'Left prose line 1'), strpos($analysis->text, '-ABV beers'));
    }

    public function testDoesNotCarryFullWidthModeIntoAsymmetricColumns(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [
            $this->entry(20, 560, str_repeat('Full width introduction ', 5)),
        ];
        for ($row = 0; $row < 5; $row++) {
            $y = 510.0 - $row * 14;
            $data[] = $this->entry(20, $y, 'Left column paragraph line ' . ($row + 1), 7);
            $data[] = $this->entry(350, $y, 'Right column paragraph line ' . ($row + 1), 7);
        }
        $data[] = $this->entry(20, 426, 'Left asymmetric continuation one');
        $data[] = $this->entry(20, 412, 'Left asymmetric continuation two');

        $analysis = $subject->analyze($data);
        $regions = $subject->diagnoseRegions($data);

        self::assertSame(['full-width', 'columns'], array_column($regions, 'type'));
        self::assertLessThan(
            strpos($analysis->text, 'Right column paragraph line 1'),
            strpos($analysis->text, 'Left asymmetric continuation two'),
        );
    }

    public function testSeparatesCentredMarkerAndFullWidthCalloutAfterColumns(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [];
        for ($row = 0; $row < 5; $row++) {
            $y = 500.0 - $row * 14;
            $data[] = $this->entry(20, $y, 'Left column line ' . ($row + 1));
            $data[] = $this->entry(350, $y - 4, 'Right column line ' . ($row + 1));
        }
        $data[] = $this->entry(300, 390, 'i');
        $data[] = $this->entry(40, 366, 'Interesting fact spanning the complete content width');
        $data[] = $this->entry(40, 352, 'Second full width callout line');
        $data[] = $this->entry(40, 338, 'Third full width callout line');

        $result = $subject->analyze($data);
        $regions = $subject->diagnoseRegions($data);

        self::assertSame('mixed', $result->layoutType);
        self::assertSame('columns', $regions[0]['type']);
        self::assertSame(
            ['full-width'],
            array_values(array_unique(array_column(array_slice($regions, 1), 'type'))),
        );
        self::assertLessThan(
            strpos($result->text, 'Right column line 1'),
            strpos($result->text, 'Left column line 5'),
        );
        self::assertLessThan(
            strpos($result->text, 'Interesting fact'),
            strpos($result->text, 'Right column line 5'),
        );
    }

    public function testSeparatesFullWidthSectionAfterCompletedColumns(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [];
        for ($row = 0; $row < 8; $row++) {
            $y = 600.0 - $row * 14;
            $data[] = $this->entry(20, $y, 'Left column sentence ' . ($row + 1));
            $data[] = $this->entry(350, $y - 3, 'Right column sentence ' . ($row + 1));
        }
        $data[] = $this->entry(20, 465, 'Healthy components of hibiscus');
        $data[] = $this->entry(28, 440, 'A full width bullet line extending across the complete content area');
        $data[] = $this->entry(28, 426, 'Another full width bullet line extending across the content area');
        $data[] = $this->entry(20, 400, 'Discussed potential health benefits');
        $data[] = $this->entry(28, 376, 'A shorter bullet');

        $result = $subject->analyze($data);
        $regions = $subject->diagnoseRegions($data);

        self::assertSame('mixed', $result->layoutType);
        self::assertSame('columns', $regions[0]['type']);
        self::assertSame(
            ['full-width'],
            array_values(array_unique(array_column(array_slice($regions, 1), 'type'))),
        );
        self::assertLessThan(
            strpos($result->text, 'Right column sentence 1'),
            strpos($result->text, 'Left column sentence 8'),
        );
        self::assertLessThan(
            strpos($result->text, 'Healthy components of hibiscus'),
            strpos($result->text, 'Right column sentence 8'),
        );
    }

    public function testJoinsHyphenatedWordAcrossColumnBoundary(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [
            $this->entry(20, 500, 'Left first line'),
            $this->entry(20, 486, 'Left second line'),
            $this->entry(20, 472, 'Left third line'),
            $this->entry(20, 458, 'known for having a posi-'),
            $this->entry(350, 496, 'tive effect on health'),
            $this->entry(350, 482, 'Right second line'),
            $this->entry(350, 468, 'Right third line'),
            $this->entry(350, 454, 'Right fourth line'),
        ];

        $result = $subject->analyze($data);

        self::assertSame('columns', $result->layoutType);
        self::assertStringContainsString('known for having a positive effect on health', $result->text);
        self::assertStringNotContainsString('posi-', $result->text);
    }

    public function testDoesNotTreatASlightlyOverestimatedColumnHeadingAsFullWidth(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [
            $this->entry(350, 550, 'Right column starts above the left side'),
            $this->entry(350, 536, 'Right column second line'),
            $this->entry(20, 522, 'Left column heading with a moderately long title'),
            $this->entry(20, 508, 'Left column first line'),
            $this->entry(350, 508, 'Right column third line'),
            $this->entry(20, 494, 'Left column second line'),
            $this->entry(350, 494, 'Right column fourth line'),
            $this->entry(20, 480, 'Left column third line'),
            $this->entry(350, 480, 'Right column fifth line'),
            $this->entry(20, 466, 'Left column fourth line'),
        ];

        $result = $subject->analyze($data);
        $regions = $subject->diagnoseRegions($data);

        self::assertSame('columns', $result->layoutType);
        self::assertCount(1, $regions);
        self::assertSame('columns', $regions[0]['type']);
        self::assertLessThan(
            strpos($result->text, 'Right column starts above'),
            strpos($result->text, 'Left column fourth line'),
        );
    }

    public function testDoesNotTreatConsecutiveOppositeSideBlocksAsParallel(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [
            $this->entry(20, 550, 'Left upper first line'),
            $this->entry(20, 536, 'Left upper second line'),
            $this->entry(20, 522, 'Left upper third line'),
            $this->entry(350, 480, 'Right lower first line'),
            $this->entry(350, 466, 'Right lower second line'),
            $this->entry(350, 452, 'Right lower third line'),
            $this->entry(20, 300, 'Left lower caption'),
            $this->entry(350, 300, 'Right lower caption'),
        ];

        $regions = $subject->diagnoseRegions($data);

        self::assertSame('full-width', $regions[0]['type']);
        self::assertSame('full-width', $regions[1]['type']);
    }

    public function testReadsNestedCentredCardGridByCard(): void
    {
        $subject = new PdfPageLayoutAnalyzer();
        $data = [
            $this->entry(42, 742, 'Major'),
            $this->entry(42, 712, 'challenges'),
            $this->entry(42, 682, 'when'),
            $this->entry(42, 652, 'reducing'),
            $this->entry(42, 622, 'cocoa'),
            $this->entry(42, 593, 'powder'),
            $this->entry(42, 563, 'in food'),
            $this->entry(42, 532, 'applications'),
            $this->entry(252, 739, 'Top left card one', 7),
            $this->entry(258, 724, 'Top left card two', 7),
            $this->entry(259, 709, 'Top left card three', 7),
            $this->entry(256, 694, 'Top left card four', 7),
            $this->entry(249, 679, 'Top left card five', 7),
            $this->entry(431, 739, 'Top right card one', 7),
            $this->entry(431, 724, 'Top right card two', 7),
            $this->entry(431, 709, 'Top right card three', 7),
            $this->entry(431, 694, 'Top right card four', 7),
            $this->entry(275, 608, 'Bottom left card one', 7),
            $this->entry(267, 593, 'Bottom left card two', 7),
            $this->entry(290, 578, 'Bottom left card three', 7),
            $this->entry(430, 608, 'Bottom right card one', 7),
            $this->entry(429, 593, 'Bottom right card two', 7),
            $this->entry(426, 578, 'Bottom right card three', 7),
            $this->entry(437, 563, 'Bottom right card four', 7),
            $this->entry(422, 548, 'Bottom right card five', 7),
        ];

        $analysis = $subject->analyze($data);
        $regions = $subject->diagnoseRegions($data);

        self::assertSame('nested-columns', $regions[0]['type']);
        self::assertLessThan(
            strpos($analysis->text, 'Top right card one'),
            strpos($analysis->text, 'Top left card five'),
        );
        self::assertLessThan(
            strpos($analysis->text, 'Bottom left card one'),
            strpos($analysis->text, 'Top right card four'),
        );
        self::assertLessThan(
            strpos($analysis->text, 'Bottom right card one'),
            strpos($analysis->text, 'Bottom left card three'),
        );
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
    private function entry(float $x, float $y, string $text, float $fontSize = 10): array
    {
        return [[1, 0, 0, 1, $x, $y], $text, 'F1', (string)$fontSize];
    }
}
