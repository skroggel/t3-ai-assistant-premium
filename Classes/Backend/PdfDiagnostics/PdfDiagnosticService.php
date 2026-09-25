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

use Madj2k\AiAssistantPremium\Indexing\Adapter\PdfTextNormalizer;
use Madj2k\AiAssistantPremium\Indexing\Pdf\DTO\PdfMarginArtifact;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfDocumentExtractionService;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;
use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Font;
use Smalot\PdfParser\PDFObject;

/**
 * Class PdfDiagnosticService
 *
 * Builds a read-only diagnostic representation of PDF extraction and layout analysis.
 *
 * @phpstan-import-type PdfPositionedTextEntryList from PdfPositionedTextReader
 * @phpstan-import-type PdfDiagnosticTextRegionList from PdfTextRegionMatcher
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfDiagnosticService
{
    private const string LLL_PREFIX =
        'LLL:EXT:ai_assistant_premium/Resources/Private/Language/locallang_pdf_diagnostics.xlf:';

    private const int MAX_PREVIEW_PAGES = 20;

    /**
     * Constructor.
     *
     * @param PdfDocumentExtractionService $documentExtractionService Shared production PDF extraction pipeline.
     * @param PdfTextNormalizer $textNormalizer Normalizer used by the indexing adapter.
     * @param PdfPositionedTextReader $positionedTextReader Reader for visual PDF text rows.
     * @param PdfTextRegionMatcher $textRegionMatcher Matcher for visual regions and normalized text.
     * @param PdfSuspiciousOverlapDetector $overlapDetector Detector for suspicious overlapping text objects.
     * @param PdfLayoutDiagnosticsProvider $layoutDiagnosticsProvider Provider using the production layout components.
     * @param PdfVectorArtworkDetector $vectorArtworkDetector Detector for non-text vector artwork regions.
     */
    public function __construct(
        private PdfDocumentExtractionService $documentExtractionService,
        private PdfTextNormalizer $textNormalizer,
        private PdfPositionedTextReader $positionedTextReader,
        private PdfTextRegionMatcher $textRegionMatcher,
        private PdfSuspiciousOverlapDetector $overlapDetector,
        private PdfLayoutDiagnosticsProvider $layoutDiagnosticsProvider,
        private PdfVectorArtworkDetector $vectorArtworkDetector,
    ) {
    }


    /**
     * Builds the complete read-only backend diagnostic model for one PDF file.
     *
     * @param string $path Absolute path to the uploaded PDF file.
     * @param string $filename Original filename displayed in the backend module.
     * @return array{filename: string, pageCount: int, pages: array<int, array<string, mixed>>} Document diagnostics based on the production extraction result.
     * @throws \Smalot\PdfParser\Exception\MissingCatalogException If the parsed document has no page catalog.
     * @throws \Exception If the PDF file cannot be parsed.
     */
    public function inspect(string $path, string $filename): array
    {
        $pdfExtractedPageList = $this->documentExtractionService->extract($path);
        $pdfDiagnosticPageList = [];

        foreach ($pdfExtractedPageList as $index => $extractedPage) {
            $pdfPage = $extractedPage->pdfPage;
            $rawPositionedText = $extractedPage->rawPositionedText;
            $result = $extractedPage->result;
            $text = $extractedPage->normalizedText;

            // Overlap warnings need the untouched object sequence; highlighting
            // uses filtered objects and therefore receives remapped continuation flags.
            $rawTextRunContinuations = $this->detectTextRunContinuations($pdfPage->getDataCommands());
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
                $pdfPage->getDetails(),
                $this->readPageContent($pdfPage->get('Contents')),
            );
            $visualization = $this->createVisualization(
                $extractedPage->positionedText,
                $pdfPage->getDetails(),
                $text,
                $index + 1,
                [
                    ...array_map(
                        static fn (PdfMarginArtifact $artifact): array => [
                            'type' => $artifact->type,
                            'label' => self::LLL_PREFIX . 'layout.type.' . $artifact->type,
                            'text' => $artifact->text,
                            'textIsTranslationKey' => false,
                            'columnCount' => 0,
                            'tableRowCount' => 0,
                            'excluded' => true,
                            'xMin' => $artifact->xMin,
                            'xMax' => $artifact->xMax,
                            'yBottom' => $artifact->yBottom,
                            'yTop' => $artifact->yTop,
                        ],
                        $extractedPage->marginArtifacts,
                    ),
                    ...$vectorArtwork,
                ],
                $pdfPage->getFonts(),
                $textRunContinuations,
            );
            $pdfDiagnosticPageList[] = [
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
            'pageCount' => count($pdfDiagnosticPageList),
            'pages' => $pdfDiagnosticPageList,
        ];
    }


    /**
     * Reads and concatenates decoded page content streams for vector diagnostics.
     *
     * @param mixed $contents Smalot page Contents value.
     * @return string Decoded PDF drawing commands, or an empty string when unavailable.
     */
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
     * Maps extracted text and detected layout geometry into interactive backend overlays.
     *
     * @param array $positionedText Filtered Smalot getDataTm() output.
     * @phpstan-param PdfPositionedTextEntryList $positionedText
     * @param array<string, mixed> $details PDF page metadata including the MediaBox.
     * @param string $text Final normalized text produced by the extraction pipeline.
     * @param int $pageNumber One-based page number.
     * @param array<int, array<string, mixed>> $marginArtifacts Excluded margin and artwork regions.
     * @param array<string, Font> $fonts Embedded fonts indexed by resource identifier.
     * @param array<int, bool> $textRunContinuations Continuation flags aligned with filtered text objects.
     * @return array{annotatedText: string, annotatedLineText: string, regions: array<int, array<string, int|float|string>>, lineRegions: array<int, array<string, int|float|string>>, layoutRegions: array<int, array<string, mixed>>} HTML annotations and normalized overlay regions.
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
                    $atom = $visualAtoms[$atom['sourceIndex']] ?? $atom;
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

        $detectedLayoutRegions = $this->layoutDiagnosticsProvider->diagnose($positionedText);
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
        $lineRegions = $this->textRegionMatcher->addTextRanges($text, $lineRegions);

        return [
            'annotatedText' => $this->textRegionMatcher->annotate($text, $regions),
            'annotatedLineText' => $this->textRegionMatcher->annotate($text, $lineRegions),
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
     * @param array $positionedText Filtered Smalot getDataTm() output.
     * @phpstan-param PdfPositionedTextEntryList $positionedText
     * @param array<int, array<string, mixed>> $visualAtoms Text atoms with resolved visual x coordinates.
     * @param array<string, Font> $fonts Embedded fonts indexed by resource identifier.
     * @param float $xOrigin Left coordinate of the page MediaBox.
     * @param float $yOrigin Bottom coordinate of the page MediaBox.
     * @param float $pageWidth Width of the page MediaBox.
     * @param float $pageHeight Height of the page MediaBox.
     * @param int $pageNumber One-based page number.
     * @param array<int, array<string, mixed>> $layoutRegions Detected layout regions in PDF coordinates.
     * @return array Normalized hoverable line overlays.
     * @phpstan-return PdfDiagnosticTextRegionList
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
                    $atoms[] = $visualAtoms[$atom['sourceIndex']] ?? $atom;
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
     * Returns all column gutters that intersect a text baseline.
     *
     * @param float $baseline Text baseline in PDF user space.
     * @param array<int, array<string, mixed>> $layoutRegions Detected layout regions.
     * @return array<int, float> Sorted gutter coordinates for the matching region.
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


    /**
     * Resolves the zero-based visual column containing an x coordinate.
     *
     * @param float $x X coordinate in PDF user space.
     * @param array<int, float> $splits Sorted gutter coordinates.
     * @return int Zero-based column index.
     */
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
     * @param array<string, mixed> $atom Positioned text atom.
     * @param array<string, Font> $fonts Embedded fonts indexed by resource identifier.
     * @return float Estimated visible glyph width in PDF user-space units.
     */
    private function calculateTextObjectWidth(array $atom, array $fonts): float
    {
        return $this->calculateTextObjectAdvance($atom, $fonts, false);
    }


    /**
     * Calculates the horizontal cursor advance of a PDF text object.
     *
     * @param array<string, mixed> $atom Positioned text atom.
     * @param array<string, Font> $fonts Embedded fonts indexed by resource identifier.
     * @param bool $includeOuterWhitespace Whether leading and trailing whitespace contributes to the advance.
     * @return float Cursor advance in PDF user-space units.
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
     * @param array<int, array<string, mixed>> $atoms Positioned text atoms.
     * @param array<int, bool> $continuations Continuation flags indexed by source text-object index.
     * @param array<string, Font> $fonts Embedded fonts indexed by resource identifier.
     * @return array<int, array<string, mixed>> Atoms indexed by source index with corrected visual positions.
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
     * Flattens the visual row model into source-indexed text atoms.
     *
     * @param array $positionedText Filtered Smalot getDataTm() output.
     * @phpstan-param PdfPositionedTextEntryList $positionedText
     * @return array<int, array<string, mixed>> Positioned text atoms in visual row order.
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
     * Detects consecutive Tj/TJ operations that advance one uninterrupted PDF text run.
     *
     * @param array<int, array<string, mixed>> $commands Decoded PDF content-stream commands.
     * @return array<int, bool> Continuation flags indexed by raw text-object index.
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
     * Reindexes raw text-run continuation flags after margin objects have been removed.
     *
     * @param array $rawEntries Unfiltered positioned text entries.
     * @phpstan-param PdfPositionedTextEntryList $rawEntries
     * @param array $filteredEntries Positioned text entries retained for indexing.
     * @phpstan-param PdfPositionedTextEntryList $filteredEntries
     * @param array<int, bool> $rawContinuations Continuation flags for raw entries.
     * @return array<int, bool> Continuation flags indexed by filtered-entry position.
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
     * Converts PDF-space layout bounds into percentage-based preview overlays.
     *
     * @param array<int, array<string, mixed>> $regions Layout regions in PDF user space.
     * @param float $xOrigin Left coordinate of the page MediaBox.
     * @param float $yOrigin Bottom coordinate of the page MediaBox.
     * @param float $pageWidth Width of the page MediaBox.
     * @param float $pageHeight Height of the page MediaBox.
     * @param int $pageNumber One-based page number.
     * @return array<int, array<string, mixed>> Regions enriched with percentage bounds and UI identifiers.
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


    /**
     * Converts an absolute coordinate into a clamped percentage.
     *
     * @param float $value Coordinate or extent to convert.
     * @param float $total Reference dimension, expected to be greater than zero.
     * @return float Value clamped to the inclusive range from 0 to 100.
     */
    private function percentage(float $value, float $total): float
    {
        return max(0.0, min(100.0, $value / $total * 100.0));
    }


    /**
     * Renders one PDF page as a PNG data URI for the backend preview.
     *
     * Rendering failures are intentionally absorbed because the textual
     * diagnostics remain useful without an image preview.
     *
     * @param string $path Absolute path to the PDF file.
     * @param int $pageIndex Zero-based page index.
     * @return string|null PNG data URI, or null when Imagick is unavailable or rendering fails.
     */
    private function renderPreview(string $path, int $pageIndex): ?string
    {
        if (!class_exists(\Imagick::class)) {
            return null;
        }

        $image = null;
        try {
            $image = new \Imagick();
            $image->setResolution(110, 110);
            $image->readImage(sprintf('%s[%d]', $path, $pageIndex));
            $image->setImageBackgroundColor('white');
            $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $image->setImageFormat('png');
            $image->thumbnailImage(1000, 0);
            $image->stripImage();
            return 'data:image/png;base64,' . base64_encode($image->getImageBlob());
        } catch (\Throwable) {
            return null;
        } finally {
            $image?->clear();
        }
    }
}
