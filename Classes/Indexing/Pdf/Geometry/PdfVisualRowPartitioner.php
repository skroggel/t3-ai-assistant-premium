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

namespace Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry;


/**
 * Class PdfVisualRowPartitioner
 *
 * Partitions visual PDF rows and parts at detected column gutters.
 *
 * @phpstan-import-type PdfVisualPartList from PdfPositionedTextReader
 * @phpstan-import-type PdfVisualRow from PdfPositionedTextReader
 * @phpstan-import-type PdfVisualRowPartition from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfVisualRowPartitioner
{
    /**
     * Constructor.
     *
     * @param PdfPositionedTextReader $positionedTextReader Reader used to reconstruct split segments.
     */
    public function __construct(
        private PdfPositionedTextReader $positionedTextReader = new PdfPositionedTextReader(),
    ) {
    }


    /**
     * Splits even a previously merged segment at the detected column gutter.
     *
     * @param array $visualRow Visual row to partition.
     * @phpstan-param PdfVisualRow $visualRow
     * @param float $split Horizontal column gutter in PDF coordinates.
     * @return array Reconstructed segments on each side.
     * @phpstan-return PdfVisualRowPartition
     */
    public function partitionAtSplit(array $visualRow, float $split): array
    {
        $leftPartList = [];
        $rightPartList = [];

        foreach ($visualRow['parts'] as $visualPart) {
            $leftAtomList = [];
            $rightAtomList = [];
            foreach ($visualPart['atoms'] ?? [] as $textAtom) {
                if ($textAtom['x'] < $split) {
                    $leftAtomList[] = $textAtom;
                } else {
                    $rightAtomList[] = $textAtom;
                }
            }
            if ($leftAtomList !== []) {
                $leftPartList[] = $this->positionedTextReader->createSegment($leftAtomList);
            }
            if ($rightAtomList !== []) {
                $rightPartList[] = $this->positionedTextReader->createSegment($rightAtomList);
            }
        }

        return ['left' => $leftPartList, 'right' => $rightPartList];
    }


    /**
     * Determines whether adjacent parts contain a gap typical of independent cells.
     *
     * @param array $visualPartList Ordered visual parts.
     * @phpstan-param PdfVisualPartList $visualPartList
     * @return bool Whether at least one gap exceeds the internal-gap threshold.
     * @deprecated Only retained for the deprecated mixed-layout renderer.
     */
    public function hasWideInternalGap(array $visualPartList): bool
    {
        for ($index = 1, $count = count($visualPartList); $index < $count; $index++) {
            if ($visualPartList[$index]['xMin'] - $visualPartList[$index - 1]['xMax'] > 30.0) {
                return true;
            }
        }
        return false;
    }
}
