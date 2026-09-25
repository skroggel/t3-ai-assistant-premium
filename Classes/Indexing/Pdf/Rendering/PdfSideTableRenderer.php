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

/**
 * Class PdfSideTableRenderer
 *
 * Reconstructs compact two-column key/value tables from visual PDF rows.
 *
 * @phpstan-import-type PdfTextAtomList from PdfPositionedTextReader
 * @phpstan-import-type PdfVisualRowList from PdfPositionedTextReader
 * @phpstan-type PdfSideTableEntry array{
 *     y: float,
 *     xMin: float,
 *     xMax: float,
 *     text: string,
 *     atoms?: PdfTextAtomList
 * }
 * @phpstan-type PdfSideTableEntryList array<int, PdfSideTableEntry>
 * @phpstan-type PdfSideTableColumnAnchor array{x: float, count: int}
 * @phpstan-type PdfSideTableColumnAnchorList array<int, PdfSideTableColumnAnchor>
 * @phpstan-type PdfSideTableRecord array{key: string, y: float, values: PdfSideTableEntryList}
 * @phpstan-type PdfSideTableRecordList array<int, PdfSideTableRecord>
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfSideTableRenderer
{
    private const float COLUMN_ANCHOR_TOLERANCE = 8.0;

    /**
     * Constructor.
     *
     * @param PdfVisualRowRenderer $visualRowRenderer Fallback renderer for unstructured rows.
     */
    public function __construct(
        private PdfVisualRowRenderer $visualRowRenderer = new PdfVisualRowRenderer(),
    ) {
    }


    /**
     * Reconstructs a compact two-column table with vertically centred or wrapping values.
     *
     * @param array $visualRowList Rows belonging to one side table.
     * @phpstan-param PdfVisualRowList $visualRowList
     * @return string Row-wise key/value representation.
     */
    public function render(array $visualRowList): string
    {
        $entryList = $this->createEntryList($visualRowList);
        if (count($entryList) < 2) {
            return implode("\n", array_column($entryList, 'text'));
        }

        $columnAnchorList = $this->detectColumnAnchorList($entryList);
        if (count($columnAnchorList) < 2) {
            return $this->visualRowRenderer->renderRows($visualRowList);
        }

        ['keyEntryList' => $keyEntryList, 'valueEntryList' => $valueEntryList] =
            $this->partitionEntryList(
                $entryList,
                $columnAnchorList[0]['x'],
                $columnAnchorList[1]['x'],
            );
        if ($keyEntryList === [] || $valueEntryList === []) {
            return $this->visualRowRenderer->renderRows($visualRowList);
        }

        return $this->renderRecordList($this->createRecordList($keyEntryList, $valueEntryList));
    }


    /**
     * Flattens visual rows while retaining the vertical position of every part.
     *
     * @param array $visualRowList Visual rows to flatten.
     * @phpstan-param PdfVisualRowList $visualRowList
     * @return array Positioned side-table entries.
     * @phpstan-return PdfSideTableEntryList
     */
    private function createEntryList(array $visualRowList): array
    {
        $entryList = [];
        foreach ($visualRowList as $visualRow) {
            foreach ($visualRow['parts'] as $visualPart) {
                $entryList[] = ['y' => (float)$visualRow['y'], ...$visualPart];
            }
        }
        return $entryList;
    }


    /**
     * Clusters similar horizontal starts into stable table-column anchors.
     *
     * @param array $entryList Positioned side-table entries.
     * @phpstan-param PdfSideTableEntryList $entryList
     * @return array Column anchors ordered from left to right.
     * @phpstan-return PdfSideTableColumnAnchorList
     */
    private function detectColumnAnchorList(array $entryList): array
    {
        $columnAnchorList = [];
        foreach ($entryList as $entry) {
            foreach ($columnAnchorList as $index => $columnAnchor) {
                if (abs($entry['xMin'] - $columnAnchor['x']) <= self::COLUMN_ANCHOR_TOLERANCE) {
                    $columnAnchorList[$index]['x'] = (
                        $columnAnchor['x'] * $columnAnchor['count'] + $entry['xMin']
                    ) / ($columnAnchor['count'] + 1);
                    $columnAnchorList[$index]['count']++;
                    continue 2;
                }
            }
            $columnAnchorList[] = ['x' => $entry['xMin'], 'count' => 1];
        }
        usort(
            $columnAnchorList,
            static fn (array $left, array $right): int => $left['x'] <=> $right['x'],
        );
        return $columnAnchorList;
    }


    /**
     * Assigns every entry to the nearest of the selected key and value anchors.
     *
     * @param array $entryList Positioned side-table entries.
     * @phpstan-param PdfSideTableEntryList $entryList
     * @param float $keyAnchor Horizontal start of the key column.
     * @param float $valueAnchor Horizontal start of the value column.
     * @return array Partitioned entries.
     * @phpstan-return array{keyEntryList: PdfSideTableEntryList, valueEntryList: PdfSideTableEntryList}
     */
    private function partitionEntryList(array $entryList, float $keyAnchor, float $valueAnchor): array
    {
        $keyEntryList = [];
        $valueEntryList = [];
        foreach ($entryList as $entry) {
            if (abs($entry['xMin'] - $keyAnchor) <= abs($entry['xMin'] - $valueAnchor)) {
                $keyEntryList[] = $entry;
            } else {
                $valueEntryList[] = $entry;
            }
        }
        return ['keyEntryList' => $keyEntryList, 'valueEntryList' => $valueEntryList];
    }


    /**
     * Creates one key/value record per key and assigns values by vertical proximity.
     *
     * @param array $keyEntryList Entries from the key column.
     * @phpstan-param PdfSideTableEntryList $keyEntryList
     * @param array $valueEntryList Entries from the value column.
     * @phpstan-param PdfSideTableEntryList $valueEntryList
     * @return array Side-table records ordered from top to bottom.
     * @phpstan-return PdfSideTableRecordList
     */
    private function createRecordList(array $keyEntryList, array $valueEntryList): array
    {
        usort($keyEntryList, static fn (array $left, array $right): int => $right['y'] <=> $left['y']);
        $recordList = array_map(
            static fn (array $key): array => ['key' => $key['text'], 'y' => $key['y'], 'values' => []],
            $keyEntryList,
        );
        foreach ($valueEntryList as $valueEntry) {
            $closestRecordIndex = 0;
            $closestDistance = PHP_FLOAT_MAX;
            foreach ($recordList as $recordIndex => $record) {
                $candidateDistance = abs($record['y'] - $valueEntry['y']);
                if ($candidateDistance < $closestDistance) {
                    $closestDistance = $candidateDistance;
                    $closestRecordIndex = $recordIndex;
                }
            }
            $recordList[$closestRecordIndex]['values'][] = $valueEntry;
        }
        return $recordList;
    }


    /**
     * Serializes reconstructed records after restoring the visual value order.
     *
     * @param array $recordList Side-table records.
     * @phpstan-param PdfSideTableRecordList $recordList
     * @return string Normalized key/value lines.
     */
    private function renderRecordList(array $recordList): string
    {
        return implode("\n", array_map(static function (array $record): string {
            usort(
                $record['values'],
                static fn (array $left, array $right): int => $right['y'] <=> $left['y']
                    ?: $left['xMin'] <=> $right['xMin'],
            );
            $value = implode(' ', array_column($record['values'], 'text'));
            return $record['key'] . ($value === '' ? '' : ' | ' . $value);
        }, $recordList));
    }
}
