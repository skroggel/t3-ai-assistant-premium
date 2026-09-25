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
 * Class PdfPositionedTextReader
 *
 * Converts Smalot text matrices into positioned atoms, visual rows and segments.
 *
 * @phpstan-type PdfTextMatrix array{
 *     0: int|float|string,
 *     1: int|float|string,
 *     2: int|float|string,
 *     3: int|float|string,
 *     4: int|float|string,
 *     5: int|float|string
 * }
 * @phpstan-type PdfPositionedTextEntry array{
 *     0: PdfTextMatrix,
 *     1: string,
 *     2?: int|string,
 *     3?: int|float|string
 * }
 * @phpstan-type PdfPositionedTextEntryList array<int, PdfPositionedTextEntry>
 * @phpstan-type PdfTextAtom array{
 *     x: float,
 *     y: float,
 *     fontSize: float,
 *     horizontalScale: float,
 *     verticalScale: float,
 *     lineCenter: float,
 *     fontId: string,
 *     rawText: string,
 *     sourceIndex: int,
 *     text: string
 * }
 * @phpstan-type PdfTextAtomList array<int, PdfTextAtom>
 * @phpstan-type PdfVisualPart array{xMin: float, xMax: float, text: string, atoms?: PdfTextAtomList}
 * @phpstan-type PdfVisualPartList array<int, PdfVisualPart>
 * @phpstan-type PdfVisualRow array{y: float, parts: PdfVisualPartList}
 * @phpstan-type PdfVisualRowList array<int, PdfVisualRow>
 * @phpstan-type PdfOpticalRow array{
 *     y: float,
 *     lineCenter: float,
 *     fontSize: float,
 *     verticalScale: float,
 *     atoms: PdfTextAtomList
 * }
 * @phpstan-type PdfOpticalRowList array<int, PdfOpticalRow>
 * @phpstan-type PdfVisualRowPartition array{left: PdfVisualPartList, right: PdfVisualPartList}
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final class PdfPositionedTextReader
{
    /**
     * Converts positioned PDF entries into sorted visual rows and horizontal segments.
     *
     * @param array $positionedText Smalot positioned text entries.
     * @phpstan-param PdfPositionedTextEntryList $positionedText
     * @return array Visual rows ordered from top to bottom.
     * @phpstan-return PdfVisualRowList
     */
    public function read(array $positionedText): array
    {
        $atoms = $this->createAtoms($positionedText);
        return $this->createSegments($this->createRows($atoms));
    }


    /**
     * Normalizes positioned entries into sortable text atoms.
     *
     * @param array $positionedText Smalot positioned text entries.
     * @phpstan-param PdfPositionedTextEntryList $positionedText
     * @return array Normalized text atoms.
     * @phpstan-return PdfTextAtomList
     */
    private function createAtoms(array $positionedText): array
    {
        $atoms = [];
        $seen = [];
        foreach ($positionedText as $sourceIndex => $entry) {
            $matrix = $entry[0];
            if (abs((float)$matrix[1]) > abs((float)$matrix[0]) * 0.2
                || abs((float)$matrix[2]) > abs((float)$matrix[3]) * 0.2
            ) {
                continue;
            }

            $rawText = strtr((string)$entry[1], [
                "\u{FB00}" => 'ff', "\u{FB01}" => 'fi', "\u{FB02}" => 'fl',
                "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl', "\u{00AD}" => '',
                "\u{00A0}" => ' ', "\u{202F}" => ' ',
            ]);
            $text = trim($rawText);
            if ($text === '') {
                continue;
            }

            $x = (float)$matrix[4];
            $y = (float)$matrix[5];
            $fontSize = $this->resolveEffectiveFontSize($entry, $matrix);
            $horizontalScale = $this->resolveEffectiveHorizontalScale($entry, $matrix);
            $verticalScale = $this->resolveEffectiveVerticalScale($entry, $matrix);
            $lineCenter = $y + $verticalScale * 0.3;
            $key = sprintf('%.2f:%.2f:%s', $x, $y, $text);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $atoms[] = [
                'x' => $x,
                'y' => $y,
                'fontSize' => $fontSize,
                'horizontalScale' => $horizontalScale,
                'verticalScale' => $verticalScale,
                'lineCenter' => $lineCenter,
                'fontId' => (string)($entry[2] ?? ''),
                'rawText' => $rawText,
                'sourceIndex' => $sourceIndex,
                'text' => $text,
            ];
        }
        usort($atoms, static function (array $left, array $right): int {
            $byY = $right['lineCenter'] <=> $left['lineCenter'];
            return $byY !== 0 ? $byY : $left['x'] <=> $right['x'];
        });
        return $atoms;
    }


    /**
     * PDF producers encode the visible font size in two common ways: either in
     * the Tf font-size operand while the text matrix has unit scale, or as a
     * unit font size with the actual scale in the text matrix. Only substitute
     * the matrix scale for the latter sentinel-style representation so regular
     * PDFs retain their existing measurements.
     *
     * @param array $entry Smalot positioned-text entry.
     * @phpstan-param PdfPositionedTextEntry $entry
     * @param array $matrix Six-element PDF text matrix.
     * @phpstan-param PdfTextMatrix $matrix
     * @return float Effective font size used by layout heuristics.
     */
    private function resolveEffectiveFontSize(array $entry, array $matrix): float
    {
        $matrixScale = abs((float)$matrix[3]);
        if (!isset($entry[3])) {
            return max(1.0, $matrixScale);
        }

        $declaredFontSize = abs((float)$entry[3]);
        if ($declaredFontSize <= 1.01 && $matrixScale > 1.01) {
            return $matrixScale;
        }

        return max(1.0, $declaredFontSize);
    }


    /**
     * Returns the rendered horizontal font scale. Unlike the conservative
     * scalar used by layout heuristics, this value includes text-matrix
     * scaling and is intended for glyph-width calculations.
     *
     * @param array $entry Smalot positioned-text entry.
     * @phpstan-param PdfPositionedTextEntry $entry
     * @param array $matrix Six-element PDF text matrix.
     * @phpstan-param PdfTextMatrix $matrix
     * @return float Rendered horizontal scale.
     */
    private function resolveEffectiveHorizontalScale(array $entry, array $matrix): float
    {
        $declaredFontSize = isset($entry[3]) ? abs((float)$entry[3]) : 1.0;
        $matrixScale = hypot((float)$matrix[0], (float)$matrix[1]);
        return max(0.01, $declaredFontSize * max(0.01, $matrixScale));
    }


    /**
     * Returns the rendered vertical font scale for diagnostic overlays. It is
     * kept separate from the conservative fontSize used by reading-order
     * heuristics so visual corrections cannot change indexed text.
     *
     * @param array $entry Smalot positioned-text entry.
     * @phpstan-param PdfPositionedTextEntry $entry
     * @param array $matrix Six-element PDF text matrix.
     * @phpstan-param PdfTextMatrix $matrix
     * @return float Rendered vertical scale.
     */
    private function resolveEffectiveVerticalScale(array $entry, array $matrix): float
    {
        $declaredFontSize = isset($entry[3]) ? abs((float)$entry[3]) : 1.0;
        $matrixScale = hypot((float)$matrix[2], (float)$matrix[3]);
        return max(0.01, $declaredFontSize * max(0.01, $matrixScale));
    }


    /**
     * Groups text atoms into optical rows using baseline and font-size tolerances.
     *
     * @param array $atoms Sorted text atoms.
     * @phpstan-param PdfTextAtomList $atoms
     * @return array Optical rows ordered from top to bottom.
     * @phpstan-return PdfOpticalRowList
     */
    private function createRows(array $atoms): array
    {
        $rows = [];
        foreach ($atoms as $atom) {
            $target = null;
            foreach ($rows as $index => $row) {
                $tolerance = max(1.5, min($atom['verticalScale'], $row['verticalScale']) * 0.28);
                if (abs($row['lineCenter'] - $atom['lineCenter']) <= $tolerance) {
                    $target = $index;
                    break;
                }
                if ($row['lineCenter'] < $atom['lineCenter'] - $tolerance) {
                    break;
                }
            }
            if ($target === null) {
                $rows[] = [
                    'y' => $atom['y'],
                    'lineCenter' => $atom['lineCenter'],
                    'fontSize' => $atom['fontSize'],
                    'verticalScale' => $atom['verticalScale'],
                    'atoms' => [$atom],
                ];
                continue;
            }
            $rows[$target]['atoms'][] = $atom;
            $atomCount = count($rows[$target]['atoms']);
            $rows[$target]['lineCenter'] = (
                $rows[$target]['lineCenter'] * ($atomCount - 1) + $atom['lineCenter']
            ) / $atomCount;
            $rows[$target]['y'] = max($rows[$target]['y'], $atom['y']);
            $rows[$target]['fontSize'] = max($rows[$target]['fontSize'], $atom['fontSize']);
            $rows[$target]['verticalScale'] = max($rows[$target]['verticalScale'], $atom['verticalScale']);
        }
        usort($rows, static fn (array $left, array $right): int => $right['lineCenter'] <=> $left['lineCenter']);
        return $rows;
    }


    /**
     * Splits optical rows into horizontal text segments at significant gaps.
     *
     * @param array $rows Optical text rows.
     * @phpstan-param PdfOpticalRowList $rows
     * @return array Rows containing reconstructed segments.
     * @phpstan-return PdfVisualRowList
     */
    private function createSegments(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $atoms = $row['atoms'];
            usort($atoms, static fn (array $left, array $right): int => $left['x'] <=> $right['x']);
            $parts = [];
            $current = [];
            $currentEnd = null;
            $currentFontSize = 0.0;
            foreach ($atoms as $atom) {
                $estimatedWidth = max($atom['fontSize'] * 0.25, mb_strlen($atom['text']) * $atom['fontSize'] * 0.58);
                $gap = $currentEnd === null ? 0.0 : $atom['x'] - $currentEnd;
                $gapTolerance = max(10.0, max($atom['fontSize'], $currentFontSize) * 1.25);
                if ($current !== [] && $gap > $gapTolerance) {
                    $parts[] = $this->createSegment($current);
                    $current = [];
                    $currentFontSize = 0.0;
                }
                $current[] = $atom;
                $currentFontSize = max($currentFontSize, $atom['fontSize']);
                $currentEnd = max($currentEnd ?? $atom['x'], $atom['x'] + $estimatedWidth);
            }
            if ($current !== []) {
                $parts[] = $this->createSegment($current);
            }
            if ($parts !== []) {
                $result[] = ['y' => $row['y'], 'parts' => $parts];
            }
        }
        return $result;
    }


    /**
     * Reconstructs one text segment and its horizontal bounds from adjacent atoms.
     *
     * @param array $atoms Adjacent atoms belonging to the segment.
     * @phpstan-param PdfTextAtomList $atoms
     * @return array Segment text, bounds and source atoms.
     * @phpstan-return PdfVisualPart
     */
    public function createSegment(array $atoms): array
    {
        $text = '';
        $previousText = '';
        $xMax = $atoms[0]['x'];
        foreach ($atoms as $atom) {
            $width = max($atom['fontSize'] * 0.25, mb_strlen($atom['text']) * $atom['fontSize'] * 0.58);
            if ($text !== ''
                && preg_match('/^[,.;:!?%)\]}]/u', $atom['text']) !== 1
                && $atom['text'] !== '-'
                && !str_ends_with($previousText, '-')
            ) {
                $text .= ' ';
            }
            $text .= $atom['text'];
            $previousText = $atom['text'];
            $xMax = max($xMax, $atom['x'] + $width);
        }
        return ['xMin' => $atoms[0]['x'], 'xMax' => $xMax, 'text' => trim($text), 'atoms' => $atoms];
    }
}
