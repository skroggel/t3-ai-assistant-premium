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

namespace Madj2k\AiAssistantPremium\Indexing\Pdf\Analysis;

use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;

/**
 * Class PdfColumnDetector
 *
 * Detects stable column gutters and recurring horizontal text anchors.
 *
 * @phpstan-import-type PdfVisualRowList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfColumnDetector
{
    private const int MIN_COLUMN_LINES = 4;

    /**
     * True columns repeatedly start at the same left edges. A full-width line
     * may contain several PDF text objects on both sides of the calculated
     * split, but their continuation positions vary from line to line.
     *
     * @param array $rows Rows to inspect for recurring anchors.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $split X coordinate of the candidate column gutter.
     * @param float $pageWidth Width of the occupied text area.
     * @return bool Whether stable anchors exist on both sides of the gutter.
     */
    public function hasStableColumnAnchors(array $rows, float $split, float $pageWidth): bool
    {
        $starts = ['left' => [], 'right' => []];
        foreach ($rows as $rowIndex => $row) {
            $rowStarts = ['left' => [], 'right' => []];
            foreach ($row['parts'] as $part) {
                foreach (($part['atoms'] ?? []) ?: [['x' => $part['xMin']]] as $atom) {
                    $side = (float)$atom['x'] < $split ? 'left' : 'right';
                    $rowStarts[$side][] = (float)$atom['x'];
                }
            }
            foreach ($rowStarts as $side => $sideStarts) {
                if ($sideStarts !== []) {
                    $starts[$side][] = ['x' => min($sideStarts), 'row' => $rowIndex];
                }
            }
        }

        $tolerance = max(8.0, $pageWidth * 0.025);
        foreach ($starts as $sideStarts) {
            $clusters = [];
            foreach ($sideStarts as $start) {
                foreach ($clusters as $index => $cluster) {
                    if (abs($start['x'] - $cluster['x']) <= $tolerance) {
                        $clusters[$index]['x'] = ($cluster['x'] * $cluster['count'] + $start['x']) / ($cluster['count'] + 1);
                        $clusters[$index]['count']++;
                        continue 2;
                    }
                }
                $clusters[] = ['x' => $start['x'], 'count' => 1];
            }
            if (max(array_column($clusters, 'count') ?: [0]) < self::MIN_COLUMN_LINES) {
                return false;
            }
        }

        return true;
    }


    /**
     * Centred card captions do not share a stable left edge. For nested
     * layouts only, fall back to a strong bimodal cluster of text starts when
     * the regular prose-column detector cannot determine a split.
     *
     * @param array $rows Rows on one side of an outer gutter.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $pageWidth Width of the inspected side.
     * @return array{count: int, split: float, confidence: float} Nested-column detection result.
     */
    public function detectNestedColumns(array $rows, float $pageWidth): array
    {
        $columns = $this->detect($rows, $pageWidth);
        if ($columns['count'] > 1) {
            return $columns;
        }

        $starts = [];
        foreach ($rows as $rowIndex => $row) {
            foreach ($row['parts'] as $part) {
                foreach (($part['atoms'] ?? []) ?: [['x' => $part['xMin']]] as $atom) {
                    $starts[] = ['x' => (float)$atom['x'], 'row' => $rowIndex];
                }
            }
        }
        usort($starts, static fn (array $left, array $right): int => $left['x'] <=> $right['x']);
        if (count($starts) < self::MIN_COLUMN_LINES * 2) {
            return $columns;
        }

        $minimumGap = max(30.0, $pageWidth * 0.18);
        $candidateList = [];
        for ($index = 1, $count = count($starts); $index < $count; $index++) {
            $gap = $starts[$index]['x'] - $starts[$index - 1]['x'];
            if ($gap < $minimumGap) {
                continue;
            }
            $split = ($starts[$index]['x'] + $starts[$index - 1]['x']) / 2;
            $leftRows = [];
            $rightRows = [];
            foreach ($starts as $start) {
                if ($start['x'] < $split) {
                    $leftRows[$start['row']] = true;
                } else {
                    $rightRows[$start['row']] = true;
                }
            }
            if (count($leftRows) < self::MIN_COLUMN_LINES
                || count($rightRows) < self::MIN_COLUMN_LINES
            ) {
                continue;
            }
            $score = $gap * min(count($leftRows), count($rightRows));
            $candidateList[] = ['split' => $split, 'score' => $score, 'gap' => $gap];
        }

        if ($candidateList === []) {
            return $columns;
        }
        usort(
            $candidateList,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score'],
        );
        $best = $candidateList[0];

        return [
            'count' => 2,
            'split' => $best['split'],
            'confidence' => min(0.9, 0.55 + $best['gap'] / max(1.0, $pageWidth) * 0.5),
        ];
    }


    /**
     * Detects a stable two-column gutter from text anchors and whitespace gaps.
     *
     * @param array $rows Rows to classify.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $pageWidth Width of the occupied text area.
     * @return array{count: int, split: float, confidence: float} Column count, gutter coordinate and confidence.
     */
    public function detect(array $rows, float $pageWidth): array
    {
        $allParts = [];
        foreach ($rows as $row) {
            foreach ($row['parts'] as $part) {
                $allParts[] = $part;
            }
        }
        if (count($allParts) < self::MIN_COLUMN_LINES * 2) {
            return ['count' => 1, 'split' => 0.0, 'confidence' => 0.0];
        }

        $split = $this->findColumnSplitFromStarts($rows, $pageWidth);
        $gapCandidates = [];
        $minimumGap = max(12.0, $pageWidth * 0.02);
        foreach ($rows as $row) {
            for ($index = 1, $count = count($row['parts']); $index < $count; $index++) {
                $leftPart = $row['parts'][$index - 1];
                $rightPart = $row['parts'][$index];
                $gap = $rightPart['xMin'] - $leftPart['xMax'];
                if ($gap >= $minimumGap) {
                    $gapCandidates[] = [
                        'split' => ($leftPart['xMax'] + $rightPart['xMin']) / 2,
                        'gap' => $gap,
                    ];
                }
            }
        }

        $clusters = [];
        $tolerance = max(6.0, $pageWidth * 0.025);
        foreach ($gapCandidates as $candidate) {
            foreach ($clusters as $index => $cluster) {
                if (abs($candidate['split'] - $cluster['split']) <= $tolerance) {
                    $clusters[$index]['split'] = (
                        $cluster['split'] * $cluster['count'] + $candidate['split']
                    ) / ($cluster['count'] + 1);
                    $clusters[$index]['count']++;
                    $clusters[$index]['totalGap'] += $candidate['gap'];
                    continue 2;
                }
            }
            $clusters[] = [
                'split' => $candidate['split'],
                'count' => 1,
                'totalGap' => $candidate['gap'],
            ];
        }

        usort($clusters, static function (array $left, array $right): int {
            $byCount = $right['count'] <=> $left['count'];
            return $byCount !== 0 ? $byCount : $right['totalGap'] <=> $left['totalGap'];
        });
        if ($split === 0.0) {
            foreach ($clusters as $cluster) {
                if ($cluster['count'] < self::MIN_COLUMN_LINES) {
                    continue;
                }
                $candidate = $cluster['split'];
                $left = count(array_filter($allParts, static fn (array $part): bool => $part['xMax'] < $candidate));
                $right = count(array_filter($allParts, static fn (array $part): bool => $part['xMin'] > $candidate));
                if ($left >= self::MIN_COLUMN_LINES && $right >= self::MIN_COLUMN_LINES) {
                    $split = $candidate;
                    break;
                }
            }
        }

        if ($split === 0.0) {
            return ['count' => 1, 'split' => 0.0, 'confidence' => 0.0];
        }

        $leftRows = [];
        $rightRows = [];
        $spanning = 0;
        foreach ($rows as $rowIndex => $row) {
            foreach ($row['parts'] as $part) {
                $positions = array_column($part['atoms'] ?? [], 'x');
                if ($positions === []) {
                    $positions = [$part['xMin']];
                }
                foreach ($positions as $position) {
                    if ((float)$position < $split) {
                        $leftRows[$rowIndex] = true;
                    } else {
                        $rightRows[$rowIndex] = true;
                    }
                }
                if ($part['xMin'] < $split && $part['xMax'] > $split) {
                    $spanning++;
                }
            }
        }
        $overlap = count(array_intersect_key($leftRows, $rightRows));
        if (count($leftRows) < self::MIN_COLUMN_LINES || count($rightRows) < self::MIN_COLUMN_LINES) {
            return ['count' => 1, 'split' => 0.0, 'confidence' => 0.0];
        }

        $sideCoverage = min(count($leftRows), count($rightRows)) / max(1, max(count($leftRows), count($rightRows)));
        $confidence = min(
            0.98,
            0.5 + $sideCoverage * 0.2 + $overlap / max(1, count($rows)) * 0.25 - $spanning * 0.005,
        );
        return ['count' => 2, 'split' => $split, 'confidence' => max(0.5, $confidence)];
    }


    /**
     * Finds persistent left edges of text columns. The cut is placed just to
     * the left of the right-hand edge, not halfway between both column starts.
     *
     * @param array $rows Rows to inspect for persistent starts.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $pageWidth Width of the occupied text area.
     * @return float Gutter coordinate, or 0.0 when no credible split exists.
     */
    private function findColumnSplitFromStarts(array $rows, float $pageWidth): float
    {
        $anchors = array_values(array_filter(
            $this->clusterStarts($rows, max(4.0, min(9.0, $pageWidth * 0.015)), true),
            static fn (array $anchor): bool => $anchor['count'] >= self::MIN_COLUMN_LINES,
        ));
        usort($anchors, static fn (array $left, array $right): int => $left['x'] <=> $right['x']);

        if ($anchors !== []) {
            $allStarts = [];
            foreach ($rows as $row) {
                foreach ($row['parts'] as $part) {
                    $starts = array_column($part['atoms'] ?? [], 'x');
                    array_push($allStarts, ...($starts === [] ? [$part['xMin']] : $starts));
                }
            }
            $minimum = min($allStarts);
            $maximum = max($allStarts);
            $startWidth = max(1.0, $maximum - $minimum);
            $target = $minimum + $startWidth * 0.5;
            $centralAnchors = array_values(array_filter(
                $anchors,
                static fn (array $anchor): bool => $anchor['x'] >= $minimum + $startWidth * 0.4
                    && $anchor['x'] <= $minimum + $startWidth * 0.65,
            ));
            if ($centralAnchors !== []) {
                usort(
                    $centralAnchors,
                    static fn (array $left, array $right): int => abs($left['x'] - $target) <=> abs($right['x'] - $target),
                );
                return $centralAnchors[0]['x'] - $this->columnSplitInset($pageWidth);
            }
        }

        $best = null;
        $bestScore = 0;
        foreach ($anchors as $leftIndex => $left) {
            foreach (array_slice($anchors, $leftIndex + 1) as $right) {
                if ($right['x'] - $left['x'] < $pageWidth * 0.3) {
                    continue;
                }
                $score = min($left['count'], $right['count']);
                if ($score > $bestScore) {
                    $best = $right['x'];
                    $bestScore = $score;
                }
            }
        }

        return $best === null ? 0.0 : $best - $this->columnSplitInset($pageWidth);
    }


    /**
     * Calculates a conservative inset that keeps right-column bullets and headings behind the gutter.
     *
     * @param float $pageWidth Width of the occupied text area.
     * @return float Inset in PDF user-space units.
     */
    public function columnSplitInset(float $pageWidth): float
    {
        // Right-column headings and bullet marks are often optically aligned
        // a few points left of the prose anchor. Keep the split safely inside
        // the gutter so those objects stay with their column.
        return max(12.0, $pageWidth * 0.025);
    }


    /**
     * Clusters recurring horizontal text starts within a configurable tolerance.
     *
     * @param array $rows Rows whose starts are clustered.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $tolerance Maximum distance between starts in one cluster.
     * @param bool $useAtoms Whether atom starts should be used instead of part starts.
     * @return array<int, array{x: float, count: int}> Averaged start coordinates and occurrence counts.
     */
    public function clusterStarts(array $rows, float $tolerance, bool $useAtoms = false): array
    {
        $clusters = [];
        foreach ($rows as $row) {
            foreach ($row['parts'] as $part) {
                $starts = $useAtoms ? array_column($part['atoms'] ?? [], 'x') : [];
                foreach ($starts === [] ? [$part['xMin']] : $starts as $start) {
                    foreach ($clusters as $index => $cluster) {
                        if (abs((float)$start - $cluster['x']) <= $tolerance) {
                            $clusters[$index]['x'] = (
                                $cluster['x'] * $cluster['count'] + (float)$start
                            ) / ($cluster['count'] + 1);
                            $clusters[$index]['count']++;
                            continue 2;
                        }
                    }
                    $clusters[] = ['x' => (float)$start, 'count' => 1];
                }
            }
        }

        return $clusters;
    }


    /**
     * Returns the leftmost occupied coordinate across all rows.
     *
     * @param array $rows Rows to inspect.
     * @phpstan-param PdfVisualRowList $rows
     * @return float Minimum x coordinate, or 0.0 for an empty set.
     */
    public function minimumX(array $rows): float
    {
        $minimum = PHP_FLOAT_MAX;
        foreach ($rows as $row) {
            foreach ($row['parts'] as $part) {
                $minimum = min($minimum, $part['xMin']);
            }
        }
        return $minimum === PHP_FLOAT_MAX ? 0.0 : $minimum;
    }


    /**
     * Returns the rightmost occupied coordinate across all rows.
     *
     * @param array $rows Rows to inspect.
     * @phpstan-param PdfVisualRowList $rows
     * @return float Maximum x coordinate, or 0.0 for an empty set.
     */
    public function maximumX(array $rows): float
    {
        $maximum = 0.0;
        foreach ($rows as $row) {
            foreach ($row['parts'] as $part) {
                $maximum = max($maximum, $part['xMax']);
            }
        }
        return $maximum;
    }
}
