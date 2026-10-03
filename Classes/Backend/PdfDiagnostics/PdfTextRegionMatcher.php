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

/**
 * Class PdfTextRegionMatcher
 *
 * Links visual PDF regions to their occurrences in normalized diagnostic text.
 *
 * @phpstan-type PdfDiagnosticTextRegion array{
 *     id: string,
 *     number: int,
 *     text: string,
 *     left: float,
 *     top: float,
 *     width: float,
 *     height: float,
 *     hue: int,
 *     textStart?: int,
 *     textEnd?: int
 * }
 * @phpstan-type PdfDiagnosticTextRegionList list<PdfDiagnosticTextRegion>
 * @phpstan-type PdfTextOccurrence array{offset: int, length: int}
 * @phpstan-type PdfTextOccurrenceList list<PdfTextOccurrence>
 * @phpstan-type PdfTextRegionMatch array{
 *     offset: int,
 *     length: int,
 *     region: PdfDiagnosticTextRegion
 * }
 * @phpstan-type PdfTextRegionMatchList list<PdfTextRegionMatch>
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfTextRegionMatcher
{
    /**
     * Wraps matched extracted-text ranges in interactive diagnostic markup.
     *
     * @param string $text Final normalized page text.
     * @param array $diagnosticRegionList Text regions to link with preview overlays.
     * @phpstan-param PdfDiagnosticTextRegionList $diagnosticRegionList
     * @return string Escaped HTML with matched ranges wrapped in mark elements.
     */
    public function annotate(string $text, array $diagnosticRegionList): string
    {
        $regionMatchList = $this->matchRegions($text, $diagnosticRegionList);

        $html = '';
        $cursor = 0;
        foreach ($regionMatchList as $regionMatch) {
            if ($regionMatch['offset'] < $cursor) {
                continue;
            }
            $html .= htmlspecialchars(
                substr($text, $cursor, $regionMatch['offset'] - $cursor),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );
            $diagnosticRegion = $regionMatch['region'];
            $matchedText = substr($text, $regionMatch['offset'], $regionMatch['length']);
            $html .= sprintf(
                '<mark class="aiassistant-pdf-diagnostics__text-region" data-region-id="%s" tabindex="0" style="--region-hue:%d">%s</mark>',
                htmlspecialchars($diagnosticRegion['id'], ENT_QUOTES, 'UTF-8'),
                $diagnosticRegion['hue'],
                htmlspecialchars($matchedText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
            $cursor = $regionMatch['offset'] + $regionMatch['length'];
        }
        $html .= htmlspecialchars(substr($text, $cursor), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return $html;
    }


    /**
     * Adds multibyte character offsets for matched normalized-text ranges to each region.
     *
     * @param string $text Final normalized page text.
     * @param array $diagnosticRegionList Visual line regions.
     * @phpstan-param PdfDiagnosticTextRegionList $diagnosticRegionList
     * @return array Regions enriched with textStart and textEnd where matched.
     * @phpstan-return PdfDiagnosticTextRegionList
     */
    public function addTextRanges(string $text, array $diagnosticRegionList): array
    {
        $textRangeByRegionId = [];
        foreach ($this->matchRegions($text, $diagnosticRegionList) as $regionMatch) {
            $prefix = substr($text, 0, $regionMatch['offset']);
            $matchedText = substr($text, $regionMatch['offset'], $regionMatch['length']);
            $start = mb_strlen($prefix);
            $textRangeByRegionId[$regionMatch['region']['id']] = [
                'textStart' => $start,
                'textEnd' => $start + mb_strlen($matchedText),
            ];
        }

        return array_map(
            static fn (array $diagnosticRegion): array => [
                ...$diagnosticRegion,
                ...($textRangeByRegionId[$diagnosticRegion['id']] ?? []),
            ],
            $diagnosticRegionList,
        );
    }


    /**
     * Matches visual text regions to non-overlapping byte ranges in normalized page text.
     *
     * @param string $text Final normalized page text.
     * @param array $diagnosticRegionList Visual text regions.
     * @phpstan-param PdfDiagnosticTextRegionList $diagnosticRegionList
     * @return array Ordered byte-range matches.
     * @phpstan-return PdfTextRegionMatchList
     */
    private function matchRegions(string $text, array $diagnosticRegionList): array
    {
        $regionMatchList = [];
        $occupiedOccurrenceList = [];

        // Match long regions first so their position provides context for
        // repeated short words that would otherwise bind to the first occurrence.
        usort(
            $diagnosticRegionList,
            static fn (array $left, array $right): int =>
                strlen($right['text']) <=> strlen($left['text']),
        );
        foreach ($diagnosticRegionList as $diagnosticRegion) {
            $candidateList = [];
            foreach ($this->matchingNeedles($diagnosticRegion['text']) as $needle) {
                foreach ($this->findOccurrences($text, $needle) as $offset) {
                    $length = strlen($needle);
                    if ($this->overlapsOccurrence($offset, $length, $occupiedOccurrenceList)) {
                        continue;
                    }
                    $candidateList[] = [
                        'offset' => $offset,
                        'length' => $length,
                    ];
                }
            }
            if ($candidateList === []) {
                continue;
            }

            $selectedOccurrence = $this->selectContextualOccurrence(
                $diagnosticRegion,
                $candidateList,
                $regionMatchList,
            );
            $regionMatchList[] = [...$selectedOccurrence, 'region' => $diagnosticRegion];
            $occupiedOccurrenceList[] = $selectedOccurrence;
        }
        usort(
            $regionMatchList,
            static fn (array $left, array $right): int =>
                $left['offset'] <=> $right['offset'] ?: $right['length'] <=> $left['length'],
        );

        return $regionMatchList;
    }


    /**
     * Chooses the occurrence that lies between already linked neighbours on
     * the same visual PDF row. Text columns are rearranged before display, so
     * identical words cannot be linked reliably by taking their first unused
     * textual occurrence. Longer neighbouring objects are processed first and
     * provide stable left/right context for short repeated words.
     *
     * @param array $diagnosticRegion Visual region being linked.
     * @phpstan-param PdfDiagnosticTextRegion $diagnosticRegion
     * @param array $candidateList Available textual occurrences.
     * @phpstan-param PdfTextOccurrenceList $candidateList
     * @param array $regionMatchList Previously linked neighbouring regions.
     * @phpstan-param PdfTextRegionMatchList $regionMatchList
     * @return array Contextually best matching occurrence.
     * @phpstan-return PdfTextOccurrence
     */
    private function selectContextualOccurrence(
        array $diagnosticRegion,
        array $candidateList,
        array $regionMatchList,
    ): array {
        if (count($candidateList) === 1 || $regionMatchList === []) {
            return $candidateList[0];
        }

        $leftNeighbour = null;
        $rightNeighbour = null;
        $regionLeft = $diagnosticRegion['left'];
        $regionTop = $diagnosticRegion['top'];
        $regionHeight = max(0.1, $diagnosticRegion['height']);

        foreach ($regionMatchList as $regionMatch) {
            $neighbour = $regionMatch['region'];
            $sameVisualRow = abs($regionTop - $neighbour['top'])
                <= max(0.25, min($regionHeight, $neighbour['height']) * 0.8);
            if (!$sameVisualRow) {
                continue;
            }

            if ($neighbour['left'] < $regionLeft
                && ($leftNeighbour === null
                    || $neighbour['left'] > $leftNeighbour['region']['left'])
            ) {
                $leftNeighbour = $regionMatch;
            } elseif ($neighbour['left'] > $regionLeft
                && ($rightNeighbour === null
                    || $neighbour['left'] < $rightNeighbour['region']['left'])
            ) {
                $rightNeighbour = $regionMatch;
            }
        }

        $lowerBound = $leftNeighbour === null
            ? null
            : $leftNeighbour['offset'] + $leftNeighbour['length'];
        $upperBound = $rightNeighbour['offset'] ?? null;
        $boundedCandidateList = array_values(array_filter(
            $candidateList,
            static fn (array $candidate): bool => ($lowerBound === null || $candidate['offset'] >= $lowerBound)
                && ($upperBound === null || $candidate['offset'] + $candidate['length'] <= $upperBound),
        ));
        if ($boundedCandidateList === []) {
            return $candidateList[0];
        }

        usort(
            $boundedCandidateList,
            static function (array $left, array $right) use ($lowerBound, $upperBound): int {
                $score = static function (array $candidate) use ($lowerBound, $upperBound): int {
                    $distance = 0;
                    if ($lowerBound !== null) {
                        $distance += $candidate['offset'] - $lowerBound;
                    }
                    if ($upperBound !== null) {
                        $distance += $upperBound - ($candidate['offset'] + $candidate['length']);
                    }
                    return $distance;
                };

                return $score($left) <=> $score($right) ?: $left['offset'] <=> $right['offset'];
            },
        );

        return $boundedCandidateList[0];
    }


    /**
     * Creates equivalent search strings for one extracted PDF text object.
     *
     * @param string $text Normalized text-object content.
     * @return list<string> Original text and applicable line-end dehyphenated variants.
     */
    private function matchingNeedles(string $text): array
    {
        $needleList = [$text];
        $withoutLineHyphen = preg_replace('/-[ \t]*$/u', '', $text) ?? $text;
        if ($withoutLineHyphen !== '' && $withoutLineHyphen !== $text) {
            $needleList[] = $withoutLineHyphen;
        }

        return $needleList;
    }


    /**
     * Finds every byte offset at which a search string occurs in normalized text.
     *
     * @param string $text Final normalized page text.
     * @param string $needle Text-object variant to locate.
     * @return list<int> Matching byte offsets in ascending order.
     */
    private function findOccurrences(string $text, string $needle): array
    {
        if ($needle === '') {
            return [];
        }
        if (mb_strlen($needle) <= 2) {
            preg_match_all(
                '/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u',
                $text,
                $matches,
                PREG_OFFSET_CAPTURE,
            );

            return array_map(static fn (array $match): int => $match[1], $matches[0]);
        }

        $offsetList = [];
        $offset = 0;
        while (($match = strpos($text, $needle, $offset)) !== false) {
            $offsetList[] = $match;
            $offset = $match + max(1, strlen($needle));
        }

        return $offsetList;
    }


    /**
     * Checks whether a candidate text range intersects an already occupied range.
     *
     * @param int $offset Candidate byte offset.
     * @param int $length Candidate byte length.
     * @param array $occupiedOccurrenceList Occupied byte ranges.
     * @phpstan-param PdfTextOccurrenceList $occupiedOccurrenceList
     * @return bool Whether the candidate overlaps an occupied range.
     */
    private function overlapsOccurrence(int $offset, int $length, array $occupiedOccurrenceList): bool
    {
        foreach ($occupiedOccurrenceList as $occupiedOccurrence) {
            if ($offset < $occupiedOccurrence['offset'] + $occupiedOccurrence['length']
                && $offset + $length > $occupiedOccurrence['offset']
            ) {
                return true;
            }
        }

        return false;
    }
}
