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

use Madj2k\AiAssistantPremium\Indexing\Adapter\PdfTextNormalizer;
use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Font;
use Smalot\PdfParser\PDFObject;

/**
 * Inspects a PDF without indexing or persisting it.
 */
final readonly class PdfDiagnosticService
{
    private const MAX_PREVIEW_PAGES = 20;

    public function __construct(
        private PdfDocumentExtractionService $documentExtractionService,
        private PdfTextNormalizer $textNormalizer,
        private PdfPositionedTextReader $positionedTextReader,
        private PdfSuspiciousOverlapDetector $overlapDetector,
        private PdfPageLayoutAnalyzer $layoutAnalyzer,
        private PdfVectorArtworkDetector $vectorArtworkDetector,
    ) {
    }

    /**
     * @return array{filename: string, pageCount: int, pages: array<int, array<string, mixed>>}
     */
    public function inspect(string $path, string $filename): array
    {
        $documentPages = $this->documentExtractionService->extract($path);
        $pages = [];

        foreach ($documentPages as $index => $extractedPage) {
            $page = $extractedPage->page;
            $rawPositionedText = $extractedPage->rawPositionedText;
            $result = $extractedPage->result;
            $text = $extractedPage->normalizedText;
            $rawTextRunContinuations = $this->detectTextRunContinuations($page->getDataCommands());
            $textRunContinuations = $this->mapTextRunContinuationsToFilteredEntries(
                $rawPositionedText,
                $extractedPage->positionedText,
                $rawTextRunContinuations,
            );
            $warnings = $this->overlapDetector->detect(
                $rawPositionedText,
                $rawTextRunContinuations,
            );
            $vectorArtwork = $this->vectorArtworkDetector->detect(
                $extractedPage->positionedText,
                $page->getDetails(),
                $this->readPageContent($page->get('Contents')),
            );
            $visualization = $this->createVisualization(
                $extractedPage->positionedText,
                $page->getDetails(),
                $text,
                $index + 1,
                [...$extractedPage->marginArtifacts, ...$vectorArtwork],
                $page->getFonts(),
                $textRunContinuations,
            );
            $pages[] = [
                'number' => $index + 1,
                'strategy' => $result->strategy,
                'layoutType' => $result->layoutType,
                'columnCount' => $result->columnCount,
                'tableRowCount' => $result->tableRowCount,
                'confidence' => round($result->confidence * 100, 1),
                'characterCount' => mb_strlen($text),
                'text' => $text,
                'annotatedText' => $visualization['annotatedText'],
                'annotatedLineText' => $visualization['annotatedLineText'],
                'regions' => $visualization['regions'],
                'lineRegions' => $visualization['lineRegions'],
                'layoutRegions' => $visualization['layoutRegions'],
                'previewDataUri' => $index < self::MAX_PREVIEW_PAGES
                    ? $this->renderPreview($path, $index)
                    : null,
                'warnings' => $warnings,
                'warningCount' => count($warnings),
                'excludedMarginArtifactCount' => count($extractedPage->marginArtifacts),
                'empty' => $text === '',
            ];
        }

        return [
            'filename' => $filename,
            'pageCount' => count($pages),
            'pages' => $pages,
        ];
    }

    private function readPageContent(mixed $contents): string
    {
        if ($contents instanceof PDFObject) {
            return $contents->getContent() ?? '';
        }
        if (!$contents instanceof ElementArray) {
            return '';
        }

        $result = '';
        $contentItems = $contents->getContent();
        if (!is_iterable($contentItems)) {
            return '';
        }
        foreach ($contentItems as $content) {
            if ($content instanceof PDFObject) {
                $result .= "\n" . ($content->getContent() ?? '');
            }
        }
        return $result;
    }

    /**
     * @param array<int, array<int, mixed>> $positionedText
     * @param array<string, mixed> $details
     * @param array<int, array<string, mixed>> $marginArtifacts
     * @param array<string, Font> $fonts
     * @param array<int, bool> $textRunContinuations
     * @return array{annotatedText: string, annotatedLineText: string, regions: array<int, array<string, int|float|string>>, lineRegions: array<int, array<string, int|float|string>>, layoutRegions: array<int, array<string, mixed>>}
     */
    private function createVisualization(
        array $positionedText,
        array $details,
        string $text,
        int $pageNumber,
        array $marginArtifacts = [],
        array $fonts = [],
        array $textRunContinuations = [],
    ): array {
        $mediaBox = $details['MediaBox'] ?? null;
        $rotation = (int)($details['Rotate'] ?? 0);
        if (!is_array($mediaBox) || count($mediaBox) < 4 || $rotation % 360 !== 0) {
            return [
                'annotatedText' => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'annotatedLineText' => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'regions' => [],
                'lineRegions' => [],
                'layoutRegions' => [],
            ];
        }

        $xOrigin = (float)$mediaBox[0];
        $yOrigin = (float)$mediaBox[1];
        $pageWidth = max(1.0, (float)$mediaBox[2] - $xOrigin);
        $pageHeight = max(1.0, (float)$mediaBox[3] - $yOrigin);
        $regions = [];
        $visualAtoms = $this->resolveVisualAtomPositions(
            $this->collectAtoms($positionedText),
            $textRunContinuations,
            $fonts,
        );

        foreach ($this->positionedTextReader->read($positionedText) as $row) {
            foreach ($row['parts'] as $part) {
                // Keep the original PDF text objects separate here. The
                // reading-order analyzer may deliberately rearrange columns,
                // so a row-wide merged segment would no longer occur verbatim
                // in the extracted text and could not be linked reliably.
                foreach ($part['atoms'] ?? [] as $atom) {
                    $atom = $visualAtoms[(int)($atom['sourceIndex'] ?? -1)] ?? $atom;
                    $fontSize = (float)($atom['fontSize'] ?? 10.0);
                    $regionText = $this->textNormalizer->normalize((string)($atom['text'] ?? ''));
                    if ($regionText === '' || preg_match('/[\p{L}\p{N}]/u', $regionText) !== 1) {
                        continue;
                    }
                    $baseline = (float)($atom['y'] ?? $row['y']);
                    $renderedFontHeight = max(
                        0.01,
                        (float)($atom['verticalScale'] ?? $fontSize),
                    );
                    $horizontalPadding = max(0.75, min(1.5, $fontSize * 0.08));
                    $estimatedWidth = $this->calculateTextObjectWidth($atom, $fonts);
                    $left = $this->percentage(
                        (float)$atom['x'] - $xOrigin - $horizontalPadding,
                        $pageWidth,
                    );
                    $top = $this->percentage(
                        $pageHeight - (($baseline - $yOrigin) + $renderedFontHeight * 0.82),
                        $pageHeight,
                    );
                    $width = max(0.35, $this->percentage(
                        $estimatedWidth + $horizontalPadding * 2,
                        $pageWidth,
                    ));
                    // PDF coordinates describe a text baseline, not a box.
                    // A compact ascender/descender model follows the visible
                    // glyphs without making adjacent lines overlap merely due
                    // to generous diagnostic padding.
                    $height = max(0.6, $this->percentage($renderedFontHeight * 1.02, $pageHeight));
                    $number = count($regions) + 1;
                    $regions[] = [
                        'id' => sprintf('page-%d-region-%d', $pageNumber, $number),
                        'number' => $number,
                        'text' => $regionText,
                        'left' => round($left, 4),
                        'top' => round($top, 4),
                        'width' => round(min($width, 100.0 - $left), 4),
                        'height' => round(min($height, 100.0 - $top), 4),
                        'hue' => ($number * 47) % 360,
                    ];
                }
            }
        }

        $detectedLayoutRegions = $this->layoutAnalyzer->diagnoseRegions($positionedText);
        $layoutRegions = [
            ...$detectedLayoutRegions,
            ...$marginArtifacts,
        ];
        usort(
            $layoutRegions,
            static fn (array $left, array $right): int => (float)$right['yTop'] <=> (float)$left['yTop'],
        );
        foreach ($layoutRegions as $index => &$layoutRegion) {
            $layoutRegion['number'] = $index + 1;
        }
        unset($layoutRegion);

        $lineRegions = $this->createLineRegions(
            $positionedText,
            $visualAtoms,
            $fonts,
            $xOrigin,
            $yOrigin,
            $pageWidth,
            $pageHeight,
            $pageNumber,
            $detectedLayoutRegions,
        );
        $lineRegions = $this->addTextRanges($text, $lineRegions);

        return [
            'annotatedText' => $this->annotateText($text, $regions),
            'annotatedLineText' => $this->annotateText($text, $lineRegions),
            'regions' => $regions,
            'lineRegions' => $lineRegions,
            'layoutRegions' => $this->normalizeLayoutRegions(
                $layoutRegions,
                $xOrigin,
                $yOrigin,
                $pageWidth,
                $pageHeight,
                $pageNumber,
            ),
        ];
    }

    /**
     * Groups adjacent PDF text objects on the same optical row. A sufficiently
     * wide horizontal gap starts a separate line segment so parallel columns
     * remain independently hoverable.
     *
     * @param array<int, array<int, mixed>> $positionedText
     * @param array<int, array<string, mixed>> $visualAtoms
     * @param array<string, Font> $fonts
     * @param array<int, array<string, mixed>> $layoutRegions
     * @return array<int, array<string, int|float|string>>
     */
    private function createLineRegions(
        array $positionedText,
        array $visualAtoms,
        array $fonts,
        float $xOrigin,
        float $yOrigin,
        float $pageWidth,
        float $pageHeight,
        int $pageNumber,
        array $layoutRegions = [],
    ): array {
        $lineRegions = [];
        foreach ($this->positionedTextReader->read($positionedText) as $row) {
            $atoms = [];
            foreach ($row['parts'] as $part) {
                foreach ($part['atoms'] ?? [] as $atom) {
                    $atoms[] = $visualAtoms[(int)($atom['sourceIndex'] ?? -1)] ?? $atom;
                }
            }
            usort($atoms, static fn (array $left, array $right): int => $left['x'] <=> $right['x']);

            $columnSplits = $this->columnSplitsForRow((float)$row['y'], $layoutRegions);

            $groups = [];
            $current = [];
            $currentEnd = null;
            $currentFontSize = 0.0;
            $currentColumn = null;
            foreach ($atoms as $atom) {
                $fontSize = (float)($atom['fontSize'] ?? 10.0);
                $width = $this->calculateTextObjectWidth($atom, $fonts);
                $gap = $currentEnd === null ? 0.0 : (float)$atom['x'] - $currentEnd;
                $gapTolerance = max(12.0, max($fontSize, $currentFontSize) * 1.3);
                $column = $this->columnIndex((float)$atom['x'], $columnSplits);
                $separateUnstructuredBlocks = $columnSplits === [] && $gap > $gapTolerance;
                if ($current !== [] && ($column !== $currentColumn || $separateUnstructuredBlocks)) {
                    $groups[] = $current;
                    $current = [];
                    $currentFontSize = 0.0;
                }
                $current[] = $atom;
                $currentFontSize = max($currentFontSize, $fontSize);
                $currentColumn = $column;
                $currentEnd = max($currentEnd ?? (float)$atom['x'], (float)$atom['x'] + $width);
            }
            if ($current !== []) {
                $groups[] = $current;
            }

            foreach ($groups as $group) {
                $segment = $this->positionedTextReader->createSegment($group);
                $lineText = $this->textNormalizer->normalize((string)$segment['text']);
                if ($lineText === '' || preg_match('/[\p{L}\p{N}]/u', $lineText) !== 1) {
                    continue;
                }

                $xMin = min(array_map(static fn (array $atom): float => (float)$atom['x'], $group));
                $xMax = max(array_map(
                    fn (array $atom): float => (float)$atom['x'] + $this->calculateTextObjectWidth($atom, $fonts),
                    $group,
                ));
                $baseline = max(array_map(static fn (array $atom): float => (float)$atom['y'], $group));
                $height = max(array_map(
                    static fn (array $atom): float => (float)($atom['verticalScale'] ?? $atom['fontSize'] ?? 10.0),
                    $group,
                ));
                $padding = max(0.75, min(1.5, $height * 0.08));
                $left = $this->percentage($xMin - $xOrigin - $padding, $pageWidth);
                $top = $this->percentage(
                    $pageHeight - (($baseline - $yOrigin) + $height * 0.82),
                    $pageHeight,
                );
                $width = $this->percentage($xMax - $xMin + $padding * 2, $pageWidth);
                $regionHeight = max(0.6, $this->percentage($height * 1.02, $pageHeight));
                $number = count($lineRegions) + 1;
                $lineRegions[] = [
                    'id' => sprintf('page-%d-line-%d', $pageNumber, $number),
                    'number' => $number,
                    'text' => $lineText,
                    'left' => round($left, 4),
                    'top' => round($top, 4),
                    'width' => round(min($width, 100.0 - $left), 4),
                    'height' => round(min($regionHeight, 100.0 - $top), 4),
                    'hue' => ($number * 47) % 360,
                ];
            }
        }

        return $lineRegions;
    }

    /**
     * @param array<int, array<string, mixed>> $layoutRegions
     * @return array<int, float>
     */
    private function columnSplitsForRow(float $baseline, array $layoutRegions): array
    {
        foreach ($layoutRegions as $region) {
            if ($baseline > (float)($region['yTop'] ?? -INF)
                || $baseline < (float)($region['yBottom'] ?? INF)
            ) {
                continue;
            }

            $splits = array_map('floatval', (array)($region['columnSplits'] ?? []));
            sort($splits, SORT_NUMERIC);
            return $splits;
        }

        return [];
    }

    /** @param array<int, float> $splits */
    private function columnIndex(float $x, array $splits): int
    {
        $column = 0;
        foreach ($splits as $split) {
            if ($x >= $split) {
                ++$column;
            }
        }
        return $column;
    }

    /**
     * Uses embedded PDF glyph widths where available and falls back to the
     * previous average-character estimate for incomplete or missing fonts.
     *
     * @param array<string, mixed> $atom
     * @param array<string, Font> $fonts
     */
    private function calculateTextObjectWidth(array $atom, array $fonts): float
    {
        return $this->calculateTextObjectAdvance($atom, $fonts, false);
    }

    /**
     * @param array<string, mixed> $atom
     * @param array<string, Font> $fonts
     */
    private function calculateTextObjectAdvance(
        array $atom,
        array $fonts,
        bool $includeOuterWhitespace,
    ): float {
        $text = (string)($includeOuterWhitespace
            ? ($atom['rawText'] ?? $atom['text'] ?? '')
            : ($atom['text'] ?? ''));
        $fontSize = (float)($atom['fontSize'] ?? 10.0);
        $fallback = max($fontSize * 0.25, mb_strlen($text) * $fontSize * 0.58);
        $font = $fonts[(string)($atom['fontId'] ?? '')] ?? null;
        if (!$font instanceof Font) {
            return $fallback;
        }
        $fontDetails = $font->getDetails();
        if (!array_key_exists('FirstChar', $fontDetails)
            || !array_key_exists('LastChar', $fontDetails)
        ) {
            return $fallback;
        }

        $missing = [];
        $fontUnits = $font->calculateTextWidth($text, $missing);
        if ($fontUnits === null || $fontUnits <= 0.0 || $missing !== []) {
            return $fallback;
        }

        $horizontalScale = (float)($atom['horizontalScale'] ?? $fontSize);
        return max($fontSize * 0.25, $fontUnits / 1000.0 * $horizontalScale);
    }

    /**
     * Smalot exposes the same Tm origin for consecutive Tj/TJ operations and
     * does not apply the text cursor advance to the next item. Preserve the
     * original coordinate for explicitly repositioned or overprinted text,
     * but advance uninterrupted runs by their rendered glyph width.
     *
     * @param array<int, array<string, mixed>> $atoms
     * @param array<int, bool> $continuations
     * @param array<string, Font> $fonts
     * @return array<int, array<string, mixed>>
     */
    private function resolveVisualAtomPositions(
        array $atoms,
        array $continuations,
        array $fonts,
    ): array {
        usort(
            $atoms,
            static fn (array $left, array $right): int => (int)$left['sourceIndex'] <=> (int)$right['sourceIndex'],
        );
        $resolved = [];
        $previous = null;
        foreach ($atoms as $atom) {
            $sourceIndex = (int)$atom['sourceIndex'];
            $originalX = (float)$atom['x'];
            if ($previous !== null
                && ($continuations[$sourceIndex] ?? false)
                && $sourceIndex === (int)$previous['sourceIndex'] + 1
                && abs((float)$atom['y'] - (float)$previous['y']) <= 0.5
                && abs($originalX - (float)$previous['originalX']) <= 0.5
            ) {
                $atom['x'] = (float)$previous['x']
                    + $this->calculateTextObjectAdvance($previous, $fonts, true);
            }
            $atom['originalX'] = $originalX;
            $resolved[$sourceIndex] = $atom;
            $previous = $atom;
        }

        return $resolved;
    }

    /**
     * @param array<int, array<int, mixed>> $positionedText
     * @return array<int, array<string, mixed>>
     */
    private function collectAtoms(array $positionedText): array
    {
        $atoms = [];
        foreach ($this->positionedTextReader->read($positionedText) as $row) {
            foreach ($row['parts'] as $part) {
                array_push($atoms, ...($part['atoms'] ?? []));
            }
        }
        return $atoms;
    }

    /**
     * @param array<int, array<string, mixed>> $commands
     * @return array<int, bool>
     */
    private function detectTextRunContinuations(array $commands): array
    {
        $continuations = [];
        $textIndex = 0;
        $positionWasReset = true;
        foreach ($commands as $command) {
            $operator = (string)($command['o'] ?? '');
            if (in_array($operator, ['BT', 'ET', 'Tm', 'Td', 'TD', 'T*', 'cm', 'q', 'Q'], true)) {
                $positionWasReset = true;
                continue;
            }
            if (!in_array($operator, ['Tj', 'TJ', "'", '"'], true)) {
                continue;
            }

            $continuations[$textIndex] = !$positionWasReset
                && in_array($operator, ['Tj', 'TJ'], true);
            $textIndex++;
            $positionWasReset = false;
        }
        return $continuations;
    }

    /**
     * @param array<int, array<int, mixed>> $rawEntries
     * @param array<int, array<int, mixed>> $filteredEntries
     * @param array<int, bool> $rawContinuations
     * @return array<int, bool>
     */
    private function mapTextRunContinuationsToFilteredEntries(
        array $rawEntries,
        array $filteredEntries,
        array $rawContinuations,
    ): array {
        $mapped = [];
        $rawIndex = 0;
        $previousMatchedRawIndex = null;
        foreach ($filteredEntries as $filteredIndex => $filteredEntry) {
            while (isset($rawEntries[$rawIndex]) && $rawEntries[$rawIndex] !== $filteredEntry) {
                $rawIndex++;
            }
            if (!isset($rawEntries[$rawIndex])) {
                break;
            }
            $mapped[$filteredIndex] = ($rawContinuations[$rawIndex] ?? false)
                && $previousMatchedRawIndex !== null
                && $rawIndex === $previousMatchedRawIndex + 1;
            $previousMatchedRawIndex = $rawIndex;
            $rawIndex++;
        }
        return $mapped;
    }

    /**
     * @param array<int, array<string, mixed>> $regions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLayoutRegions(
        array $regions,
        float $xOrigin,
        float $yOrigin,
        float $pageWidth,
        float $pageHeight,
        int $pageNumber,
    ): array {
        return array_map(function (array $region) use (
            $xOrigin,
            $yOrigin,
            $pageWidth,
            $pageHeight,
            $pageNumber,
        ): array {
            $padding = 4.0;
            $left = $this->percentage((float)$region['xMin'] - $xOrigin - $padding, $pageWidth);
            $top = $this->percentage(
                $pageHeight - ((float)$region['yTop'] - $yOrigin) - $padding,
                $pageHeight,
            );
            $width = $this->percentage(
                (float)$region['xMax'] - (float)$region['xMin'] + $padding * 2,
                $pageWidth,
            );
            $height = $this->percentage(
                (float)$region['yTop'] - (float)$region['yBottom'] + $padding * 2,
                $pageHeight,
            );
            $number = (int)$region['number'];

            return [
                ...$region,
                'id' => sprintf('page-%d-layout-%d', $pageNumber, $number),
                'left' => round($left, 4),
                'top' => round($top, 4),
                'width' => round(min($width, 100.0 - $left), 4),
                'height' => round(min($height, 100.0 - $top), 4),
                'hue' => ($number * 67 + 190) % 360,
            ];
        }, $regions);
    }

    private function percentage(float $value, float $total): float
    {
        return max(0.0, min(100.0, $value / $total * 100.0));
    }

    /**
     * @param array<int, array<string, int|float|string>> $regions
     */
    private function annotateText(string $text, array $regions): string
    {
        $matches = $this->matchRegions($text, $regions);

        $html = '';
        $cursor = 0;
        foreach ($matches as $match) {
            if ($match['offset'] < $cursor) {
                continue;
            }
            $html .= htmlspecialchars(
                substr($text, $cursor, $match['offset'] - $cursor),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );
            $region = $match['region'];
            $matchedText = substr($text, $match['offset'], $match['length']);
            $html .= sprintf(
                '<mark class="aiassistant-pdf-diagnostics__text-region" data-region-id="%s" tabindex="0" style="--region-hue:%d">%s</mark>',
                htmlspecialchars((string)$region['id'], ENT_QUOTES, 'UTF-8'),
                (int)$region['hue'],
                htmlspecialchars($matchedText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
            $cursor = $match['offset'] + $match['length'];
        }
        $html .= htmlspecialchars(substr($text, $cursor), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return $html;
    }

    /**
     * @param array<int, array<string, int|float|string>> $regions
     * @return array<int, array{offset: int, length: int, region: array<string, int|float|string>}>
     */
    private function matchRegions(string $text, array $regions): array
    {
        $matches = [];
        $occupied = [];
        usort($regions, static fn (array $left, array $right): int =>
            strlen((string)$right['text']) <=> strlen((string)$left['text'])
        );
        foreach ($regions as $region) {
            $candidates = [];
            foreach ($this->matchingNeedles((string)$region['text']) as $needle) {
                foreach ($this->findOccurrences($text, $needle) as $offset) {
                    $length = strlen($needle);
                    if ($this->overlapsMatch($offset, $length, $occupied)) {
                        continue;
                    }
                    $candidates[] = [
                        'offset' => $offset,
                        'length' => $length,
                    ];
                }
            }
            if ($candidates === []) {
                continue;
            }

            $selected = $this->selectContextualOccurrence($region, $candidates, $matches);
            $matches[] = [...$selected, 'region' => $region];
            $occupied[] = $selected;
        }
        usort($matches, static fn (array $left, array $right): int =>
            $left['offset'] <=> $right['offset'] ?: $right['length'] <=> $left['length']
        );
        return $matches;
    }

    /**
     * @param array<int, array<string, int|float|string>> $regions
     * @return array<int, array<string, int|float|string>>
     */
    private function addTextRanges(string $text, array $regions): array
    {
        $ranges = [];
        foreach ($this->matchRegions($text, $regions) as $match) {
            $prefix = substr($text, 0, $match['offset']);
            $matchedText = substr($text, $match['offset'], $match['length']);
            $start = mb_strlen($prefix);
            $ranges[(string)$match['region']['id']] = [
                'textStart' => $start,
                'textEnd' => $start + mb_strlen($matchedText),
            ];
        }

        return array_map(
            static fn (array $region): array => [
                ...$region,
                ...($ranges[(string)$region['id']] ?? []),
            ],
            $regions,
        );
    }

    /**
     * Chooses the occurrence that lies between already linked neighbours on
     * the same visual PDF row. Text columns are rearranged before display, so
     * identical words cannot be linked reliably by taking their first unused
     * textual occurrence. Longer neighbouring objects are processed first and
     * provide stable left/right context for short repeated words.
     *
     * @param array<string, int|float|string> $region
     * @param array<int, array{offset: int, length: int}> $candidates
     * @param array<int, array{offset: int, length: int, region: array<string, int|float|string>}> $matches
     * @return array{offset: int, length: int}
     */
    private function selectContextualOccurrence(array $region, array $candidates, array $matches): array
    {
        if (count($candidates) === 1 || $matches === []) {
            return $candidates[0];
        }

        $leftNeighbour = null;
        $rightNeighbour = null;
        $regionLeft = (float)($region['left'] ?? 0.0);
        $regionTop = (float)($region['top'] ?? 0.0);
        $regionHeight = max(0.1, (float)($region['height'] ?? 0.0));

        foreach ($matches as $match) {
            $neighbour = $match['region'];
            $neighbourTop = (float)($neighbour['top'] ?? 0.0);
            $neighbourHeight = max(0.1, (float)($neighbour['height'] ?? 0.0));
            $sameVisualRow = abs($regionTop - $neighbourTop)
                <= max(0.25, min($regionHeight, $neighbourHeight) * 0.8);
            if (!$sameVisualRow) {
                continue;
            }

            $neighbourLeft = (float)($neighbour['left'] ?? 0.0);
            if ($neighbourLeft < $regionLeft
                && ($leftNeighbour === null
                    || $neighbourLeft > (float)$leftNeighbour['region']['left'])
            ) {
                $leftNeighbour = $match;
            } elseif ($neighbourLeft > $regionLeft
                && ($rightNeighbour === null
                    || $neighbourLeft < (float)$rightNeighbour['region']['left'])
            ) {
                $rightNeighbour = $match;
            }
        }

        $lowerBound = $leftNeighbour === null
            ? null
            : $leftNeighbour['offset'] + $leftNeighbour['length'];
        $upperBound = $rightNeighbour['offset'] ?? null;
        $bounded = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => ($lowerBound === null || $candidate['offset'] >= $lowerBound)
                && ($upperBound === null || $candidate['offset'] + $candidate['length'] <= $upperBound),
        ));
        if ($bounded === []) {
            return $candidates[0];
        }

        usort($bounded, static function (array $left, array $right) use ($lowerBound, $upperBound): int {
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
        });

        return $bounded[0];
    }

    /** @return array<int, string> */
    private function matchingNeedles(string $text): array
    {
        $needles = [$text];
        $withoutLineHyphen = preg_replace('/-[ \t]*$/u', '', $text) ?? $text;
        if ($withoutLineHyphen !== '' && $withoutLineHyphen !== $text) {
            $needles[] = $withoutLineHyphen;
        }
        return $needles;
    }

    /** @return array<int, int> */
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
            return array_map(static fn (array $match): int => $match[1], $matches[0] ?? []);
        }

        $offsets = [];
        $offset = 0;
        while (($match = strpos($text, $needle, $offset)) !== false) {
            $offsets[] = $match;
            $offset = $match + max(1, strlen($needle));
        }
        return $offsets;
    }

    /**
     * @param array<int, array{offset: int, length: int}> $matches
     */
    private function overlapsMatch(int $offset, int $length, array $matches): bool
    {
        foreach ($matches as $match) {
            if ($offset < $match['offset'] + $match['length']
                && $offset + $length > $match['offset']
            ) {
                return true;
            }
        }
        return false;
    }

    private function renderPreview(string $path, int $pageIndex): ?string
    {
        if (!class_exists(\Imagick::class)) {
            return null;
        }

        try {
            $image = new \Imagick();
            $image->setResolution(110, 110);
            $image->readImage(sprintf('%s[%d]', $path, $pageIndex));
            $image->setImageBackgroundColor('white');
            $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $image->setImageFormat('png');
            $image->thumbnailImage(1000, 0);
            $image->stripImage();
            $dataUri = 'data:image/png;base64,' . base64_encode($image->getImageBlob());
            $image->clear();
            $image->destroy();

            return $dataUri;
        } catch (\Throwable) {
            return null;
        }
    }
}
