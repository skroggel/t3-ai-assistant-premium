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

namespace Madj2k\AiAssistantPremium\Indexing\Pdf\Rendering;

use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfVisualRowPartitioner;

/**
 * Class PdfLayoutRenderer
 *
 * Serializes detected PDF rows, columns and mixed layouts in reading order.
 *
 * @phpstan-import-type PdfVisualRowList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfLayoutRenderer
{
    private const int MIN_COLUMN_LINES = 4;

    /**
     * Constructor.
     *
     * @param PdfPositionedTextReader $positionedTextReader Reader used to reconstruct multi-column segments.
     * @param PdfVisualRowRenderer $visualRowRenderer Renderer for visual rows and sequential text blocks.
     * @param PdfVisualRowPartitioner $visualRowPartitioner Partitioner for rows crossing a column gutter.
     * @param PdfSideTableRenderer $sideTableRenderer Renderer for compact key/value side tables.
     */
    public function __construct(
        private PdfPositionedTextReader $positionedTextReader = new PdfPositionedTextReader(),
        private PdfVisualRowRenderer $visualRowRenderer = new PdfVisualRowRenderer(),
        private PdfVisualRowPartitioner $visualRowPartitioner = new PdfVisualRowPartitioner(),
        private PdfSideTableRenderer $sideTableRenderer = new PdfSideTableRenderer(),
    ) {
    }


    /**
     * Serializes visual rows while retaining cell separators between independent parts.
     *
     * @param array $visualRowList Visual rows.
     * @phpstan-param PdfVisualRowList $visualRowList
     * @return string Row-wise text joined by line breaks.
     */
    public function renderRows(array $visualRowList): string
    {
        return $this->visualRowRenderer->renderRows($visualRowList);
    }


    /**
     * Serializes ordinary page-wide prose without introducing table-cell
     * separators between PDF text objects on the same visual line.
     *
     * @param array $visualRowList Visual rows.
     * @phpstan-param PdfVisualRowList $visualRowList
     * @return string Plain text in visual row order.
     */
    public function renderPlainRows(array $visualRowList): string
    {
        return $this->visualRowRenderer->renderPlainRows($visualRowList);
    }


    /**
     * Serializes a page-wide two-column layout in reading order.
     *
     * @param array $visualRowList Visual rows.
     * @phpstan-param PdfVisualRowList $visualRowList
     * @param float $split Horizontal column gutter in PDF coordinates.
     * @return string Text before the columns, followed by left, right and trailing blocks.
     * @deprecated The regional layout pipeline supersedes page-wide column rendering.
     */
    public function renderColumns(array $visualRowList, float $split): string
    {
        $before = [];
        $left = [];
        $right = [];
        $after = [];
        $columnRows = [];

        foreach ($visualRowList as $rowIndex => $row) {
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
        $lastColumnRow = $columnRows === [] ? count($visualRowList) - 1 : max(array_keys($columnRows));

        foreach ($visualRowList as $rowIndex => $row) {
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

        return $this->visualRowRenderer->joinSequentialBlocks(array_filter([
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
     * @param array $visualRowList Rows inside the bounded region.
     * @phpstan-param PdfVisualRowList $visualRowList
     * @param float $split Horizontal column gutter in PDF coordinates.
     * @param bool $preserveCells Whether side content should retain table-cell separators.
     * @return string Region text in column-by-column reading order.
     */
    public function renderColumnRegion(array $visualRowList, float $split, bool $preserveCells = false): string
    {
        $leftRows = [];
        $rightRows = [];

        foreach ($visualRowList as $row) {
            $partition = $this->visualRowPartitioner->partitionAtSplit($row, $split);
            if ($partition['left'] !== []) {
                $leftRows[] = ['y' => $row['y'], 'parts' => $partition['left']];
            }
            if ($partition['right'] !== []) {
                $rightRows[] = ['y' => $row['y'], 'parts' => $partition['right']];
            }
        }

        $renderSide = fn (array $sideRows): string => $preserveCells
            ? $this->sideTableRenderer->render($sideRows)
            : $this->visualRowRenderer->renderPlainRows($sideRows);

        return $this->visualRowRenderer->joinSequentialBlocks(array_filter([
            $renderSide($leftRows),
            $renderSide($rightRows),
        ], static fn (string $block): bool => $block !== ''));
    }


    /**
     * Renders three or more independent card-like columns from top to bottom,
     * preserving leading headings before the first shared card row.
     *
     * @param array $visualRowList Rows inside the region.
     * @phpstan-param PdfVisualRowList $visualRowList
     * @param array<int, float> $splits Ordered horizontal column gutters.
     * @return string Region text in multi-column reading order.
     */
    public function renderMultiColumnRegion(array $visualRowList, array $splits): string
    {
        sort($splits);
        $columnCount = count($splits) + 1;
        $participation = array_fill(0, $columnCount, []);
        $partitionedRows = [];

        foreach ($visualRowList as $rowIndex => $row) {
            $columns = array_fill(0, $columnCount, []);
            foreach ($row['parts'] as $part) {
                $atomsByColumn = array_fill(0, $columnCount, []);
                foreach (($part['atoms'] ?? []) ?: [] as $atom) {
                    $column = 0;
                    while (isset($splits[$column]) && (float)$atom['x'] >= $splits[$column]) {
                        $column++;
                    }
                    $atomsByColumn[$column][] = $atom;
                }
                foreach ($atomsByColumn as $column => $atoms) {
                    if ($atoms !== []) {
                        $columns[$column][] = $this->positionedTextReader->createSegment($atoms);
                        $participation[$column][$rowIndex] = true;
                    }
                }
            }
            $partitionedRows[$rowIndex] = $columns;
        }

        $firstSharedRow = null;
        foreach (array_keys($visualRowList) as $rowIndex) {
            if (array_reduce(
                $participation,
                static fn (bool $all, array $items): bool => $all && isset($items[$rowIndex]),
                true,
            )) {
                $firstSharedRow = $rowIndex;
                break;
            }
        }
        if ($firstSharedRow === null) {
            return $this->renderRows($visualRowList);
        }

        $blocks = [];
        if ($firstSharedRow > 0) {
            $blocks[] = $this->renderRows(array_slice($visualRowList, 0, $firstSharedRow));
        }
        for ($column = 0; $column < $columnCount; $column++) {
            $lines = [];
            foreach ($partitionedRows as $rowIndex => $columns) {
                if ($rowIndex < $firstSharedRow || $columns[$column] === []) {
                    continue;
                }
                $lines[] = implode(' ', array_column($columns[$column], 'text'));
            }
            if ($lines !== []) {
                $blocks[] = implode("\n", $lines);
            }
        }

        return $this->visualRowRenderer->joinSequentialBlocks($blocks);
    }


    /**
     * Keeps sparse/tabular rows in visual row order while reading dense prose
     * regions column by column.
     *
     * @param array $visualRowList Visual rows.
     * @phpstan-param PdfVisualRowList $visualRowList
     * @param float $split Horizontal column gutter in PDF coordinates.
     * @return string Text with prose regions ordered by column and sparse rows kept visually ordered.
     * @deprecated The regional layout pipeline now classifies and renders mixed layouts directly.
     */
    public function renderMixedLayout(array $visualRowList, float $split): string
    {
        $candidates = [];
        foreach ($visualRowList as $index => $row) {
            ['left' => $leftParts, 'right' => $rightParts] =
                $this->visualRowPartitioner->partitionAtSplit($row, $split);
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
            $hasWideInternalGap = $this->visualRowPartitioner->hasWideInternalGap($leftParts)
                || $this->visualRowPartitioner->hasWideInternalGap($rightParts);
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
            return $this->renderRows($visualRowList);
        }

        $blocks = [];
        $rowIndex = 0;
        foreach ($runs as [$start, $end]) {
            if ($rowIndex < $start) {
                $blocks[] = $this->renderRows(array_slice($visualRowList, $rowIndex, $start - $rowIndex));
            }

            $left = [];
            $right = [];
            for ($index = $start; $index <= $end; $index++) {
                $partition = $this->visualRowPartitioner->partitionAtSplit($visualRowList[$index], $split);
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

        if ($rowIndex < count($visualRowList)) {
            $blocks[] = $this->renderRows(array_slice($visualRowList, $rowIndex));
        }

        return implode("\n\n", array_filter($blocks));
    }
}
