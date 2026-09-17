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
 * Serializes detected PDF rows, columns and mixed layouts.
 */
final readonly class PdfLayoutRenderer
{
    private const MIN_COLUMN_LINES = 4;

    public function __construct(
        private PdfPositionedTextReader $positionedTextReader = new PdfPositionedTextReader(),
    ) {
    }

    /**
     * @param array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}> $rows
     */
    public function renderRows(array $rows): string
    {
        return implode("\n", array_map(
            static fn (array $row): string => implode(' | ', array_column($row['parts'], 'text')),
            $rows,
        ));
    }

    /**
     * @param array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}> $rows
     */
    public function renderColumns(array $rows, float $split): string
    {
        $before = [];
        $left = [];
        $right = [];
        $after = [];
        $columnRows = [];

        foreach ($rows as $rowIndex => $row) {
            $hasLeft = false;
            $hasRight = false;
            foreach ($row['parts'] as $part) {
                $hasLeft = $hasLeft || $part['xMax'] < $split;
                $hasRight = $hasRight || $part['xMin'] > $split;
            }
            if ($hasLeft || $hasRight) {
                $columnRows[$rowIndex] = true;
            }
        }

        $firstColumnRow = $columnRows === [] ? 0 : min(array_keys($columnRows));
        $lastColumnRow = $columnRows === [] ? count($rows) - 1 : max(array_keys($columnRows));

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex < $firstColumnRow) {
                $before[] = implode(' ', array_column($row['parts'], 'text'));
                continue;
            }
            if ($rowIndex > $lastColumnRow) {
                $after[] = implode(' ', array_column($row['parts'], 'text'));
                continue;
            }

            foreach ($row['parts'] as $part) {
                if ($part['xMax'] < $split) {
                    $left[] = $part['text'];
                } elseif ($part['xMin'] > $split) {
                    $right[] = $part['text'];
                } else {
                    $left[] = $part['text'];
                }
            }
        }

        return implode("\n\n", array_filter([
            implode("\n", $before),
            implode("\n", $left),
            implode("\n", $right),
            implode("\n", $after),
        ], static fn (string $block): bool => $block !== ''));
    }

    /**
     * Renders one bounded page region column by column. Unlike renderColumns(),
     * this method deliberately has no concept of page-wide before/after rows.
     *
     * @param array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}> $rows
     */
    public function renderColumnRegion(array $rows, float $split, bool $preserveCells = false): string
    {
        $leftRows = [];
        $rightRows = [];

        foreach ($rows as $row) {
            $partition = $this->partitionRowAtSplit($row, $split);
            if ($partition['left'] !== []) {
                $leftRows[] = ['y' => $row['y'], 'parts' => $partition['left']];
            }
            if ($partition['right'] !== []) {
                $rightRows[] = ['y' => $row['y'], 'parts' => $partition['right']];
            }
        }

        $renderSide = fn (array $sideRows): string => $preserveCells
            ? $this->renderSideTable($sideRows)
            : implode("\n", array_map(
                static fn (array $row): string => implode(' ', array_column($row['parts'], 'text')),
                $sideRows,
            ));

        return implode("\n\n", array_filter([
            $renderSide($leftRows),
            $renderSide($rightRows),
        ], static fn (string $block): bool => $block !== ''));
    }

    /**
     * Reconstructs a compact two-column table whose first-column value can be
     * vertically centred next to a wrapping second-column value.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     */
    private function renderSideTable(array $rows): string
    {
        $entries = [];
        foreach ($rows as $row) {
            foreach ($row['parts'] as $part) {
                $entries[] = ['y' => (float)$row['y'], ...$part];
            }
        }
        if (count($entries) < 2) {
            return implode("\n", array_column($entries, 'text'));
        }

        $anchors = [];
        foreach ($entries as $entry) {
            foreach ($anchors as $index => $anchor) {
                if (abs($entry['xMin'] - $anchor['x']) <= 8.0) {
                    $anchors[$index]['x'] = (
                        $anchor['x'] * $anchor['count'] + $entry['xMin']
                    ) / ($anchor['count'] + 1);
                    $anchors[$index]['count']++;
                    continue 2;
                }
            }
            $anchors[] = ['x' => $entry['xMin'], 'count' => 1];
        }
        usort($anchors, static fn (array $left, array $right): int => $left['x'] <=> $right['x']);
        if (count($anchors) < 2) {
            return implode("\n", array_map(
                static fn (array $row): string => implode(' | ', array_column($row['parts'], 'text')),
                $rows,
            ));
        }

        $keyAnchor = $anchors[0]['x'];
        $valueAnchor = $anchors[1]['x'];
        $keys = [];
        $values = [];
        foreach ($entries as $entry) {
            if (abs($entry['xMin'] - $keyAnchor) <= abs($entry['xMin'] - $valueAnchor)) {
                $keys[] = $entry;
            } else {
                $values[] = $entry;
            }
        }
        if ($keys === [] || $values === []) {
            return implode("\n", array_map(
                static fn (array $row): string => implode(' | ', array_column($row['parts'], 'text')),
                $rows,
            ));
        }

        usort($keys, static fn (array $left, array $right): int => $right['y'] <=> $left['y']);
        $records = array_map(
            static fn (array $key): array => ['key' => $key['text'], 'y' => $key['y'], 'values' => []],
            $keys,
        );
        foreach ($values as $value) {
            $closest = 0;
            $distance = PHP_FLOAT_MAX;
            foreach ($records as $index => $record) {
                $candidate = abs($record['y'] - $value['y']);
                if ($candidate < $distance) {
                    $distance = $candidate;
                    $closest = $index;
                }
            }
            $records[$closest]['values'][] = $value;
        }

        return implode("\n", array_map(static function (array $record): string {
            usort(
                $record['values'],
                static fn (array $left, array $right): int => $right['y'] <=> $left['y']
                    ?: $left['xMin'] <=> $right['xMin'],
            );
            $value = implode(' ', array_column($record['values'], 'text'));
            return $record['key'] . ($value === '' ? '' : ' | ' . $value);
        }, $records));
    }

    /**
     * Keeps sparse/tabular rows in visual row order while reading dense prose
     * regions column by column.
     *
     * @param array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}> $rows
     */
    public function renderMixedLayout(array $rows, float $split): string
    {
        $candidates = [];
        foreach ($rows as $index => $row) {
            ['left' => $leftParts, 'right' => $rightParts] = $this->partitionRowAtSplit($row, $split);
            if ($leftParts === [] || $rightParts === []) {
                continue;
            }

            $parts = [...$leftParts, ...$rightParts];
            usort($parts, static fn (array $left, array $right): int => $left['xMin'] <=> $right['xMin']);
            $span = max(1.0, end($parts)['xMax'] - $parts[0]['xMin']);
            $filled = array_sum(array_map(
                static fn (array $part): float => max(0.0, $part['xMax'] - $part['xMin']),
                $parts,
            ));
            $hasWideInternalGap = $this->hasWideInternalGap($leftParts)
                || $this->hasWideInternalGap($rightParts);
            if (!$hasWideInternalGap && $filled / $span >= 0.45) {
                $candidates[] = $index;
            }
        }

        $runs = [];
        $run = [];
        foreach ($candidates as $index) {
            if ($run !== [] && $index - end($run) > 2) {
                if (count($run) >= self::MIN_COLUMN_LINES) {
                    $runs[] = [min($run), max($run)];
                }
                $run = [];
            }
            $run[] = $index;
        }
        if (count($run) >= self::MIN_COLUMN_LINES) {
            $runs[] = [min($run), max($run)];
        }

        if ($runs === []) {
            return $this->renderRows($rows);
        }

        $blocks = [];
        $rowIndex = 0;
        foreach ($runs as [$start, $end]) {
            if ($rowIndex < $start) {
                $blocks[] = $this->renderRows(array_slice($rows, $rowIndex, $start - $rowIndex));
            }

            $left = [];
            $right = [];
            for ($index = $start; $index <= $end; $index++) {
                $partition = $this->partitionRowAtSplit($rows[$index], $split);
                if ($partition['left'] !== []) {
                    $left[] = implode(' ', array_column($partition['left'], 'text'));
                }
                if ($partition['right'] !== []) {
                    $right[] = implode(' ', array_column($partition['right'], 'text'));
                }
            }
            $blocks[] = implode("\n", $left);
            $blocks[] = implode("\n", $right);
            $rowIndex = $end + 1;
        }

        if ($rowIndex < count($rows)) {
            $blocks[] = $this->renderRows(array_slice($rows, $rowIndex));
        }

        return implode("\n\n", array_filter($blocks));
    }

    /**
     * Splits even a previously merged segment at the detected column gutter.
     *
     * @param array{y: float, parts: array<int, array<string, mixed>>} $row
     * @return array{left: array<int, array<string, mixed>>, right: array<int, array<string, mixed>>}
     */
    private function partitionRowAtSplit(array $row, float $split): array
    {
        $left = [];
        $right = [];

        foreach ($row['parts'] as $part) {
            $leftAtoms = [];
            $rightAtoms = [];
            foreach ($part['atoms'] ?? [] as $atom) {
                if ($atom['x'] < $split) {
                    $leftAtoms[] = $atom;
                } else {
                    $rightAtoms[] = $atom;
                }
            }
            if ($leftAtoms !== []) {
                $left[] = $this->positionedTextReader->createSegment($leftAtoms);
            }
            if ($rightAtoms !== []) {
                $right[] = $this->positionedTextReader->createSegment($rightAtoms);
            }
        }

        return ['left' => $left, 'right' => $right];
    }

    /**
     * @param array<int, array{xMin: float, xMax: float, text: string}> $parts
     */
    private function hasWideInternalGap(array $parts): bool
    {
        for ($index = 1, $count = count($parts); $index < $count; $index++) {
            if ($parts[$index]['xMin'] - $parts[$index - 1]['xMax'] > 30.0) {
                return true;
            }
        }
        return false;
    }

}
