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

namespace Madj2k\AiAssistantPremium\Indexing\Pdf\Preprocessing;

use Madj2k\AiAssistantPremium\Indexing\Pdf\DTO\PdfMarginAnalysisInput;
use Madj2k\AiAssistantPremium\Indexing\Pdf\DTO\PdfMarginAnalysisResult;
use Madj2k\AiAssistantPremium\Indexing\Pdf\DTO\PdfMarginArtifact;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;

/**
 * Class PdfMarginArtifactDetector
 *
 * Detects repeated headers, footers and page-number sequences across a PDF document.
 *
 * @phpstan-import-type PdfPositionedTextEntry from PdfPositionedTextReader
 * @phpstan-type PdfMarginCandidate array{
 *     key: string,
 *     pageIndex: int,
 *     pageNumber: int,
 *     entryIndex: int,
 *     entry: PdfPositionedTextEntry,
 *     zone: string,
 *     text: string,
 *     signature: string,
 *     xRatio: float,
 *     yRatio: float,
 *     printedPageNumber?: int
 * }
 * @phpstan-type PdfMarginCandidateList list<PdfMarginCandidate>
 * @phpstan-type PdfMarginCandidateClusterList list<PdfMarginCandidateList>
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfMarginArtifactDetector
{
    private const float MARGIN_RATIO = 0.08;
    private const float X_POSITION_TOLERANCE = 0.04;
    private const float Y_POSITION_TOLERANCE = 0.02;

    /**
     * Constructor.
     *
     * @param PdfPositionedTextReader $positionedTextReader Reader used to derive visual rows and bounds.
     */
    public function __construct(
        private PdfPositionedTextReader $positionedTextReader = new PdfPositionedTextReader(),
    ) {
    }


    /**
     * Removes confirmed recurring margin artifacts from all extracted pages.
     *
     * @param array<int, PdfMarginAnalysisInput> $pdfPageList PDF page inputs in document order.
     * @return array<int, PdfMarginAnalysisResult> Filtered page results indexed by source page.
     */
    public function analyze(array $pdfPageList): array
    {
        $candidates = $this->collectCandidates($pdfPageList);

        // Confirm repeated labels and changing page numbers separately, then
        // include neighbouring objects from the same aligned margin line.
        $excluded = $this->detectRepeatedText($candidates, count($pdfPageList));
        foreach ($this->detectPageNumberSequences($candidates, count($pdfPageList)) as $key => $confirmed) {
            $excluded[$key] = $confirmed;
        }
        $excluded = $this->expandToAlignedMarginLine($candidates, $excluded);

        $result = [];
        foreach ($pdfPageList as $pageIndex => $pdfPage) {
            $excludedByZone = ['header' => [], 'footer' => []];
            foreach ($candidates as $candidate) {
                if ($candidate['pageIndex'] !== $pageIndex || !isset($excluded[$candidate['key']])) {
                    continue;
                }
                $excludedByZone[$candidate['zone']][] = $candidate;
            }

            $excludedIndexes = [];
            $artifacts = [];
            foreach ($excludedByZone as $zone => $zoneCandidates) {
                if ($zoneCandidates === []) {
                    continue;
                }
                foreach ($zoneCandidates as $candidate) {
                    $excludedIndexes[$candidate['entryIndex']] = true;
                }
                $artifact = $this->createArtifact($zone, $zoneCandidates);
                if ($artifact !== null) {
                    $artifacts[] = $artifact;
                }
            }

            $result[$pageIndex] = new PdfMarginAnalysisResult(
                array_values(array_filter(
                    $pdfPage->positionedText,
                    static fn (array $entry, int $entryIndex): bool => !isset($excludedIndexes[$entryIndex]),
                    ARRAY_FILTER_USE_BOTH,
                )),
                $artifacts,
            );
        }

        return $result;
    }


    /**
     * Collects horizontal text objects located inside page header and footer bands.
     *
     * @param array<int, PdfMarginAnalysisInput> $pdfPageList PDF page inputs in document order.
     * @return array Margin candidates enriched with normalized positions and signatures.
     * @phpstan-return PdfMarginCandidateList
     */
    private function collectCandidates(array $pdfPageList): array
    {
        $candidates = [];
        foreach ($pdfPageList as $pageIndex => $pdfPage) {
            $mediaBox = $pdfPage->details['MediaBox'] ?? null;
            if (!is_array($mediaBox) || count($mediaBox) < 4) {
                continue;
            }
            $xOrigin = (float)$mediaBox[0];
            $yOrigin = (float)$mediaBox[1];
            $width = max(1.0, (float)$mediaBox[2] - $xOrigin);
            $height = max(1.0, (float)$mediaBox[3] - $yOrigin);

            foreach ($pdfPage->positionedText as $entryIndex => $entry) {
                $matrix = $entry[0];
                if (abs((float)$matrix[1]) > abs((float)$matrix[0]) * 0.2
                    || abs((float)$matrix[2]) > abs((float)$matrix[3]) * 0.2
                ) {
                    continue;
                }
                $text = $this->normalizeText($entry[1]);
                if ($text === '') {
                    continue;
                }

                $xRatio = ((float)$matrix[4] - $xOrigin) / $width;
                $yRatio = ((float)$matrix[5] - $yOrigin) / $height;
                $zone = match (true) {
                    $yRatio <= self::MARGIN_RATIO => 'footer',
                    $yRatio >= 1.0 - self::MARGIN_RATIO => 'header',
                    default => null,
                };
                if ($zone === null) {
                    continue;
                }

                $candidates[] = [
                    'key' => $pageIndex . ':' . $entryIndex,
                    'pageIndex' => $pageIndex,
                    'pageNumber' => $pageIndex + 1,
                    'entryIndex' => $entryIndex,
                    'entry' => $entry,
                    'zone' => $zone,
                    'text' => $text,
                    'signature' => mb_strtolower($text),
                    'xRatio' => $xRatio,
                    'yRatio' => $yRatio,
                ];
            }
        }

        return $candidates;
    }


    /**
     * Confirms margin candidates whose text repeats at a stable position across pages.
     *
     * @param array $candidates Collected header and footer candidates.
     * @phpstan-param PdfMarginCandidateList $candidates
     * @param int $pageCount Total number of pages in the document.
     * @return array<string, bool> Confirmed candidate keys.
     */
    private function detectRepeatedText(array $candidates, int $pageCount): array
    {
        $groups = [];
        foreach ($candidates as $candidate) {
            $groups[$candidate['zone'] . ':' . $candidate['signature']][] = $candidate;
        }

        $confirmed = [];
        foreach ($groups as $group) {
            foreach ($this->clusterByPosition($group) as $cluster) {
                if ($this->uniquePageCount($cluster) < $this->requiredOccurrences($pageCount)) {
                    continue;
                }
                foreach ($cluster as $candidate) {
                    $confirmed[$candidate['key']] = true;
                }
            }
        }
        return $confirmed;
    }


    /**
     * A running header or footer often consists of several PDF text objects,
     * while only one of them repeats verbatim. Once an anchor is confirmed,
     * include only objects on the same visual baseline, not the entire margin.
     *
     * @param array $candidates Collected header and footer candidates.
     * @phpstan-param PdfMarginCandidateList $candidates
     * @param array<string, bool> $confirmed Previously confirmed candidate keys.
     * @return array<string, bool> Confirmed candidate keys including aligned companion objects.
     */
    private function expandToAlignedMarginLine(array $candidates, array $confirmed): array
    {
        $anchors = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => isset($confirmed[$candidate['key']]),
        ));
        foreach ($candidates as $candidate) {
            foreach ($anchors as $anchor) {
                if ($candidate['pageIndex'] === $anchor['pageIndex']
                    && $candidate['zone'] === $anchor['zone']
                    && abs($candidate['yRatio'] - $anchor['yRatio']) <= self::Y_POSITION_TOLERANCE
                ) {
                    $confirmed[$candidate['key']] = true;
                    break;
                }
            }
        }
        return $confirmed;
    }


    /**
     * Detects printed page numbers that form a consistent sequence across the document.
     *
     * @param array $candidates Collected header and footer candidates.
     * @phpstan-param PdfMarginCandidateList $candidates
     * @param int $pageCount Total number of pages in the document.
     * @return array<string, bool> Confirmed page-number candidate keys.
     */
    private function detectPageNumberSequences(array $candidates, int $pageCount): array
    {
        $numberCandidates = [];
        $confirmed = [];
        foreach ($candidates as $candidate) {
            if (preg_match('/^(?:page\s*)?(\d+)(?:\s*(?:of|\/)\s*\d+)?$/iu', $candidate['text'], $matches) !== 1) {
                continue;
            }
            $candidate['printedPageNumber'] = (int)$matches[1];
            if ($pageCount === 1 && $candidate['printedPageNumber'] === 1) {
                $confirmed[$candidate['key']] = true;
            }
            $numberCandidates[$candidate['zone']][] = $candidate;
        }
        foreach ($numberCandidates as $zoneCandidates) {
            foreach ($this->clusterByPosition($zoneCandidates) as $cluster) {
                $byOffset = [];
                foreach ($cluster as $candidate) {
                    $offset = $candidate['printedPageNumber'] - $candidate['pageNumber'];
                    $byOffset[$offset][] = $candidate;
                }
                foreach ($byOffset as $sequence) {
                    if ($this->uniquePageCount($sequence) < $this->requiredOccurrences($pageCount)) {
                        continue;
                    }
                    foreach ($sequence as $candidate) {
                        $confirmed[$candidate['key']] = true;
                    }
                }
            }
        }
        return $confirmed;
    }


    /**
     * Groups margin candidates whose normalized positions are sufficiently close.
     *
     * @param array $candidates Candidates to cluster.
     * @phpstan-param PdfMarginCandidateList $candidates
     * @return array Position-based candidate clusters.
     * @phpstan-return PdfMarginCandidateClusterList
     */
    private function clusterByPosition(array $candidates): array
    {
        $clusters = [];
        foreach ($candidates as $candidate) {
            foreach ($clusters as $index => $cluster) {
                $anchor = $cluster[0];
                if (abs($candidate['xRatio'] - $anchor['xRatio']) <= self::X_POSITION_TOLERANCE
                    && abs($candidate['yRatio'] - $anchor['yRatio']) <= self::Y_POSITION_TOLERANCE
                ) {
                    $clusters[$index][] = $candidate;
                    continue 2;
                }
            }
            $clusters[] = [$candidate];
        }
        return $clusters;
    }


    /**
     * Creates a diagnostic layout artifact for one excluded margin line.
     *
     * @param string $zone Margin zone, either header or footer.
     * @param array $candidates Confirmed candidates belonging to the artifact.
     * @phpstan-param PdfMarginCandidateList $candidates
     * @return PdfMarginArtifact|null Margin artifact, or null when no visual row can be derived.
     */
    private function createArtifact(string $zone, array $candidates): ?PdfMarginArtifact
    {
        $rows = $this->positionedTextReader->read(array_column($candidates, 'entry'));
        if ($rows === []) {
            return null;
        }

        $xMin = PHP_FLOAT_MAX;
        $xMax = 0.0;
        $yBottom = PHP_FLOAT_MAX;
        $yTop = 0.0;
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = implode(' | ', array_column($row['parts'], 'text'));
            foreach ($row['parts'] as $part) {
                $fontSize = max(array_map(
                    static fn (array $atom): float => (float)$atom['fontSize'],
                    ($part['atoms'] ?? []) ?: [['fontSize' => 10.0]],
                ));
                $xMin = min($xMin, (float)$part['xMin']);
                $xMax = max($xMax, (float)$part['xMax']);
                $yBottom = min($yBottom, (float)$row['y'] - $fontSize * 0.25);
                $yTop = max($yTop, (float)$row['y'] + $fontSize);
            }
        }

        return new PdfMarginArtifact(
            $zone,
            ucfirst($zone),
            implode("\n", $lines),
            $xMin,
            $xMax,
            $yBottom,
            $yTop,
        );
    }


    /**
     * Counts the distinct source pages represented by a candidate set.
     *
     * @param array $candidates Candidates whose page coverage is counted.
     * @phpstan-param PdfMarginCandidateList $candidates
     * @return int Number of distinct pages.
     */
    private function uniquePageCount(array $candidates): int
    {
        return count(array_unique(array_column($candidates, 'pageIndex')));
    }


    /**
     * Calculates the minimum recurrence required to classify a margin item as an artifact.
     *
     * @param int $pageCount Total number of pages in the document.
     * @return int Required number of matching pages.
     */
    private function requiredOccurrences(int $pageCount): int
    {
        return $pageCount <= 3 ? 2 : max(3, (int)ceil($pageCount * 0.3));
    }


    /**
     * Normalizes extracted margin text for stable comparison across pages.
     *
     * @param string $text Extracted candidate text.
     * @return string Whitespace-normalized text without invisible formatting characters.
     */
    private function normalizeText(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{00AD}", "\u{00A0}", "\u{202F}"], ['', '', ' ', ' '], $text);
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
