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
 * Finds differing text objects painted at nearly identical coordinates.
 *
 * This is deliberately diagnostic only: overlap can indicate hidden template
 * text, overprinting or intentional visual effects and is not safe to remove
 * automatically during indexing.
 */
final readonly class PdfSuspiciousOverlapDetector
{
    private const MAX_WARNINGS = 10;

    /**
     * @param array<int, array<int, mixed>> $positionedText
     * @return array<int, array{firstText: string, secondText: string, x: float, y: float, overlap: float}>
     */
    public function detect(array $positionedText): array
    {
        $objects = [];
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

            $fontSize = max(1.0, isset($entry[3]) ? abs((float)$entry[3]) : abs((float)$matrix[3]));
            $width = max($fontSize * 0.25, mb_strlen($text) * $fontSize * 0.58);
            $objects[] = [
                'text' => $text,
                'xMin' => (float)$matrix[4],
                'xMax' => (float)$matrix[4] + $width,
                'y' => (float)$matrix[5],
                'fontSize' => $fontSize,
            ];
        }

        $warnings = [];
        for ($leftIndex = 0, $count = count($objects); $leftIndex < $count; $leftIndex++) {
            $left = $objects[$leftIndex];
            for ($rightIndex = $leftIndex + 1; $rightIndex < $count; $rightIndex++) {
                $right = $objects[$rightIndex];
                if ($left['text'] === $right['text']) {
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
}
