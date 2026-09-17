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
 * Detects repeated headers, footers and page-number sequences from the
 * geometry of all pages in one PDF document.
 */
final readonly class PdfMarginArtifactDetector
{
    private const MARGIN_RATIO = 0.08;
    private const X_POSITION_TOLERANCE = 0.04;
    private const Y_POSITION_TOLERANCE = 0.02;

    public function __construct(
        private PdfPositionedTextReader $positionedTextReader = new PdfPositionedTextReader(),
    ) {
    }

    /**
     * @param array<int, array{positionedText: array<int, array<int, mixed>>, details: array<string, mixed>}> $pages
     * @return array<int, array{positionedText: array<int, array<int, mixed>>, artifacts: array<int, array<string, mixed>>}>
     */
    public function analyze(array $pages): array
    {
        $candidates = $this->collectCandidates($pages);
        $excluded = $this->detectRepeatedText($candidates, count($pages));
        foreach ($this->detectPageNumberSequences($candidates, count($pages)) as $key => $confirmed) {
            $excluded[$key] = $confirmed;
        }
        $excluded = $this->expandToAlignedMarginLine($candidates, $excluded);

        $result = [];
        foreach ($pages as $pageIndex => $page) {
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

            $result[$pageIndex] = [
                'positionedText' => array_values(array_filter(
                    $page['positionedText'],
                    static fn (array $entry, int $entryIndex): bool => !isset($excludedIndexes[$entryIndex]),
                    ARRAY_FILTER_USE_BOTH,
                )),
                'artifacts' => $artifacts,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array{positionedText: array<int, array<int, mixed>>, details: array<string, mixed>}> $pages
     * @return array<int, array<string, mixed>>
     */
    private function collectCandidates(array $pages): array
    {
        $candidates = [];
        foreach ($pages as $pageIndex => $page) {
            $mediaBox = $page['details']['MediaBox'] ?? null;
            if (!is_array($mediaBox) || count($mediaBox) < 4) {
                continue;
            }
            $xOrigin = (float)$mediaBox[0];
            $yOrigin = (float)$mediaBox[1];
            $width = max(1.0, (float)$mediaBox[2] - $xOrigin);
            $height = max(1.0, (float)$mediaBox[3] - $yOrigin);

            foreach ($page['positionedText'] as $entryIndex => $entry) {
                $matrix = $entry[0] ?? null;
                if (!is_array($matrix) || !isset($matrix[0], $matrix[1], $matrix[2], $matrix[3], $matrix[4], $matrix[5])) {
                    continue;
                }
                if (abs((float)$matrix[1]) > abs((float)$matrix[0]) * 0.2
                    || abs((float)$matrix[2]) > abs((float)$matrix[3]) * 0.2
                ) {
                    continue;
                }
                $text = $this->normalizeText((string)($entry[1] ?? ''));
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

    /** @param array<int, array<string, mixed>> $candidates */
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
     * @param array<int, array<string, mixed>> $candidates
     * @param array<string, bool> $confirmed
     * @return array<string, bool>
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

    /** @param array<int, array<string, mixed>> $candidates */
    private function detectPageNumberSequences(array $candidates, int $pageCount): array
    {
        $numberCandidates = [];
        foreach ($candidates as $candidate) {
            if (preg_match('/^(?:page\s*)?(\d+)(?:\s*(?:of|\/)\s*\d+)?$/iu', $candidate['text'], $matches) !== 1) {
                continue;
            }
            $candidate['printedPageNumber'] = (int)$matches[1];
            $numberCandidates[$candidate['zone']][] = $candidate;
        }

        $confirmed = [];
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
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<int, array<string, mixed>>>
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

    /** @param array<int, array<string, mixed>> $candidates */
    private function createArtifact(string $zone, array $candidates): ?array
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
                    static fn (array $atom): float => (float)($atom['fontSize'] ?? 10.0),
                    ($part['atoms'] ?? []) ?: [['fontSize' => 10.0]],
                ));
                $xMin = min($xMin, (float)$part['xMin']);
                $xMax = max($xMax, (float)$part['xMax']);
                $yBottom = min($yBottom, (float)$row['y'] - $fontSize * 0.25);
                $yTop = max($yTop, (float)$row['y'] + $fontSize);
            }
        }

        return [
            'type' => $zone,
            'label' => ucfirst($zone),
            'text' => implode("\n", $lines),
            'columnCount' => 0,
            'tableRowCount' => 0,
            'excluded' => true,
            'xMin' => $xMin,
            'xMax' => $xMax,
            'yBottom' => $yBottom,
            'yTop' => $yTop,
        ];
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function uniquePageCount(array $candidates): int
    {
        return count(array_unique(array_column($candidates, 'pageIndex')));
    }

    private function requiredOccurrences(int $pageCount): int
    {
        return $pageCount <= 3 ? 2 : max(3, (int)ceil($pageCount * 0.3));
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{00AD}", "\u{00A0}", "\u{202F}"], ['', '', ' ', ' '], $text);
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
