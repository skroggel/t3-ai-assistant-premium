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

namespace Madj2k\AiAssistantPremium\Backend\PdfDiagnostics;

use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;

/**
 * Class PdfSuspiciousOverlapDetector
 *
 * Detects potentially suspicious text objects painted at nearly identical coordinates.
 *
 * @phpstan-import-type PdfPositionedTextEntryList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfSuspiciousOverlapDetector
{
    private const int MAX_WARNINGS = 10;

    /**
     * Detects different text objects occupying nearly identical page coordinates.
     *
     * @param array $positionedText Smalot positioned text entries.
     * @phpstan-param PdfPositionedTextEntryList $positionedText
     * @param array<int, bool> $textRunContinuations Entries known to continue the same PDF text run.
     * @return array<int, array{firstText: string, secondText: string, x: float, y: float, overlap: float}> Suspicious overlaps for backend display.
     */
    public function detect(array $positionedText, array $textRunContinuations = []): array
    {
        $objects = [];
        foreach ($positionedText as $sourceIndex => $entry) {
            $matrix = $entry[0];
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

            $fontSize = max(1.0, isset($entry[3]) ? abs((float)$entry[3]) : abs((float)$matrix[3]));
            $width = max($fontSize * 0.25, mb_strlen($text) * $fontSize * 0.58);
            $objects[] = [
                'sourceIndex' => $sourceIndex,
                'text' => $text,
                'xMin' => (float)$matrix[4],
                'xMax' => (float)$matrix[4] + $width,
                'y' => (float)$matrix[5],
                'fontSize' => $fontSize,
            ];
        }

        $warnings = [];

        // Adjacent objects from one PDF text run may overlap intentionally;
        // only independent objects sharing a baseline and origin are suspicious.
        for ($leftIndex = 0, $count = count($objects); $leftIndex < $count; $leftIndex++) {
            $left = $objects[$leftIndex];
            for ($rightIndex = $leftIndex + 1; $rightIndex < $count; $rightIndex++) {
                $right = $objects[$rightIndex];
                if ($left['text'] === $right['text']) {
                    continue;
                }
                if ($this->belongsToSameContinuousTextRun(
                    (int)$left['sourceIndex'],
                    (int)$right['sourceIndex'],
                    $textRunContinuations,
                )) {
                    continue;
                }
                $yTolerance = max(1.5, min($left['fontSize'], $right['fontSize']) * 0.2);
                if (abs($left['y'] - $right['y']) > $yTolerance) {
                    continue;
                }
                $startTolerance = max(8.0, min($left['fontSize'], $right['fontSize']) * 1.5);
                if (abs($left['xMin'] - $right['xMin']) > $startTolerance) {
                    continue;
                }

                $overlapWidth = min($left['xMax'], $right['xMax']) - max($left['xMin'], $right['xMin']);
                $shorterWidth = min(
                    $left['xMax'] - $left['xMin'],
                    $right['xMax'] - $right['xMin'],
                );
                $overlap = $overlapWidth / max(1.0, $shorterWidth);
                if ($overlap < 0.72) {
                    continue;
                }

                $warnings[] = [
                    'firstText' => $left['text'],
                    'secondText' => $right['text'],
                    'x' => round(min($left['xMin'], $right['xMin']), 2),
                    'y' => round(($left['y'] + $right['y']) / 2, 2),
                    'overlap' => round($overlap * 100, 1),
                ];
                if (count($warnings) >= self::MAX_WARNINGS) {
                    return $warnings;
                }
            }
        }

        return $warnings;
    }


    /**
     * Determines whether two overlapping objects belong to one continuous text run.
     *
     * @param int $firstIndex Source index of the first text object.
     * @param int $secondIndex Source index of the second text object.
     * @param array<int, bool> $continuations Continuation flags keyed by source index.
     * @return bool Whether the apparent overlap is an intentional run continuation.
     */
    private function belongsToSameContinuousTextRun(
        int $firstIndex,
        int $secondIndex,
        array $continuations,
    ): bool {
        if ($secondIndex <= $firstIndex) {
            return false;
        }
        for ($index = $firstIndex + 1; $index <= $secondIndex; $index++) {
            if (!($continuations[$index] ?? false)) {
                return false;
            }
        }
        return true;
    }
}
