<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Madj2k\AiAssistantPremium\Indexing\Pdf;

/**
 * Converts Smalot text matrices into horizontal visual rows and segments.
 */
final class PdfPositionedTextReader
{
    /**
     * @param array<int, array<int, mixed>> $positionedText
     * @return array<int, array<string, mixed>>
     */
    public function read(array $positionedText): array
    {
        $atoms = $this->createAtoms($positionedText);
        return $this->createSegments($this->createRows($atoms));
    }

    /** @param array<int, array<int, mixed>> $positionedText */
    private function createAtoms(array $positionedText): array
    {
        $atoms = [];
        $seen = [];
        foreach ($positionedText as $sourceIndex => $entry) {
            if (!isset($entry[0], $entry[1]) || !is_array($entry[0])) {
                continue;
            }
            $matrix = $entry[0];
            if (!isset($matrix[0], $matrix[1], $matrix[2], $matrix[3], $matrix[4], $matrix[5])) {
                continue;
            }
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
     * @param array<int, mixed> $entry
     * @param array<int, mixed> $matrix
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
     * @param array<int, mixed> $entry
     * @param array<int, mixed> $matrix
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
     * @param array<int, mixed> $entry
     * @param array<int, mixed> $matrix
     */
    private function resolveEffectiveVerticalScale(array $entry, array $matrix): float
    {
        $declaredFontSize = isset($entry[3]) ? abs((float)$entry[3]) : 1.0;
        $matrixScale = hypot((float)$matrix[2], (float)$matrix[3]);
        return max(0.01, $declaredFontSize * max(0.01, $matrixScale));
    }

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

    /** @param array<int, array<string, mixed>> $atoms */
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
