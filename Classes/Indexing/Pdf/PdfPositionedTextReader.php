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
        foreach ($positionedText as $entry) {
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

            $text = trim(strtr((string)$entry[1], [
                "\u{FB00}" => 'ff', "\u{FB01}" => 'fi', "\u{FB02}" => 'fl',
                "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl', "\u{00AD}" => '',
                "\u{00A0}" => ' ', "\u{202F}" => ' ',
            ]));
            if ($text === '') {
                continue;
            }

            $x = (float)$matrix[4];
            $y = (float)$matrix[5];
            $fontSize = max(1.0, isset($entry[3]) ? abs((float)$entry[3]) : abs((float)$matrix[3]));
            $key = sprintf('%.2f:%.2f:%s', $x, $y, $text);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $atoms[] = ['x' => $x, 'y' => $y, 'fontSize' => $fontSize, 'text' => $text];
        }
        usort($atoms, static function (array $left, array $right): int {
            $byY = $right['y'] <=> $left['y'];
            return $byY !== 0 ? $byY : $left['x'] <=> $right['x'];
        });
        return $atoms;
    }

    private function createRows(array $atoms): array
    {
        $rows = [];
        foreach ($atoms as $atom) {
            $target = null;
            foreach ($rows as $index => $row) {
                $tolerance = max(1.5, min($atom['fontSize'], $row['fontSize']) * 0.25);
                if (abs($row['y'] - $atom['y']) <= $tolerance) {
                    $target = $index;
                    break;
                }
                if ($row['y'] < $atom['y'] - $tolerance) {
                    break;
                }
            }
            if ($target === null) {
                $rows[] = ['y' => $atom['y'], 'fontSize' => $atom['fontSize'], 'atoms' => [$atom]];
                continue;
            }
            $rows[$target]['atoms'][] = $atom;
            $rows[$target]['fontSize'] = max($rows[$target]['fontSize'], $atom['fontSize']);
        }
        usort($rows, static fn (array $left, array $right): int => $right['y'] <=> $left['y']);
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
            foreach ($atoms as $atom) {
                $estimatedWidth = max($atom['fontSize'] * 0.25, mb_strlen($atom['text']) * $atom['fontSize'] * 0.58);
                $gap = $currentEnd === null ? 0.0 : $atom['x'] - $currentEnd;
                if ($current !== [] && $gap > max(10.0, $atom['fontSize'] * 1.25)) {
                    $parts[] = $this->createSegment($current);
                    $current = [];
                }
                $current[] = $atom;
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
