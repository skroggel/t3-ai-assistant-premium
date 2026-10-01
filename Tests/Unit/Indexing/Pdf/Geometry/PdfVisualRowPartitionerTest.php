<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, version 3.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Madj2k\AiAssistantPremium\Tests\Unit\Indexing\Pdf\Geometry;

use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfVisualRowPartitioner;
use PHPUnit\Framework\TestCase;

/**
 * Class PdfVisualRowPartitionerTest
 *
 * Verifies geometric partitioning of visual PDF rows.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final class PdfVisualRowPartitionerTest extends TestCase
{
    /**
     * Verifies that text atoms are reconstructed on the correct side of a gutter.
     */
    public function testPartitionsAtomsAtColumnGutter(): void
    {
        $visualRow = [
            'y' => 100.0,
            'parts' => [[
                'xMin' => 10.0,
                'xMax' => 140.0,
                'text' => 'Left Right',
                'atoms' => [
                    $this->textAtom(10.0, 'Left'),
                    $this->textAtom(100.0, 'Right'),
                ],
            ]],
        ];

        $result = (new PdfVisualRowPartitioner())->partitionAtSplit($visualRow, 60.0);

        self::assertSame('Left', $result['left'][0]['text']);
        self::assertSame('Right', $result['right'][0]['text']);
    }


    /**
     * Creates a minimal positioned text atom for partitioning tests.
     *
     * @param float $x Horizontal position.
     * @param string $text Atom text.
     * @return array<string, int|float|string> Positioned atom.
     */
    private function textAtom(float $x, string $text): array
    {
        return [
            'x' => $x,
            'y' => 100.0,
            'fontSize' => 10.0,
            'horizontalScale' => 10.0,
            'verticalScale' => 10.0,
            'lineCenter' => 103.0,
            'fontId' => 'F1',
            'rawText' => $text,
            'sourceIndex' => 0,
            'text' => $text,
        ];
    }
}
