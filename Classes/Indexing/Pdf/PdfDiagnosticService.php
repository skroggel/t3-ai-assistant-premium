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
use Smalot\PdfParser\Config as PdfParserConfig;
use Smalot\PdfParser\Parser;

/**
 * Inspects a PDF without indexing or persisting it.
 */
final readonly class PdfDiagnosticService
{
    private const MAX_PREVIEW_PAGES = 20;

    public function __construct(
        private PdfPageTextExtractor $pageTextExtractor,
        private PdfTextNormalizer $textNormalizer,
        private PdfPositionedTextReader $positionedTextReader,
        private PdfSuspiciousOverlapDetector $overlapDetector,
        private PdfPageLayoutAnalyzer $layoutAnalyzer,
        private PdfMarginArtifactDetector $marginArtifactDetector,
    ) {
    }

    /**
     * @return array{filename: string, pageCount: int, pages: array<int, array<string, mixed>>}
     */
    public function inspect(string $path, string $filename): array
    {
        $parserConfig = new PdfParserConfig();
        $parserConfig->setDataTmFontInfoHasToBeIncluded(true);
        $document = (new Parser([], $parserConfig))->parseFile($path);
        $documentPages = $document->getPages();
        $marginAnalysis = $this->marginArtifactDetector->analyze(array_map(
            static fn ($page): array => [
                'positionedText' => $page->getDataTm(),
                'details' => $page->getDetails(),
            ],
            $documentPages,
        ));
        $pages = [];

        foreach ($documentPages as $index => $page) {
            $pageMarginAnalysis = $marginAnalysis[$index] ?? [
                'positionedText' => $page->getDataTm(),
                'artifacts' => [],
            ];
            $result = $this->pageTextExtractor->extract(
                $page,
                $pageMarginAnalysis['positionedText'],
                $pageMarginAnalysis['artifacts'] !== [],
            );
            $text = $this->textNormalizer->normalize($result->text);
            $warnings = $this->overlapDetector->detect($page->getDataTm());
            $visualization = $this->createVisualization(
                $pageMarginAnalysis['positionedText'],
                $page->getDetails(),
                $text,
                $index + 1,
                $pageMarginAnalysis['artifacts'],
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
                'regions' => $visualization['regions'],
                'layoutRegions' => $visualization['layoutRegions'],
                'previewDataUri' => $index < self::MAX_PREVIEW_PAGES
                    ? $this->renderPreview($path, $index)
                    : null,
                'warnings' => $warnings,
                'warningCount' => count($warnings),
                'excludedMarginArtifactCount' => count($pageMarginAnalysis['artifacts']),
                'empty' => $text === '',
            ];
        }

        return [
            'filename' => $filename,
            'pageCount' => count($pages),
            'pages' => $pages,
        ];
    }

    /**
     * @param array<int, array<int, mixed>> $positionedText
     * @param array<string, mixed> $details
     * @param array<int, array<string, mixed>> $marginArtifacts
     * @return array{annotatedText: string, regions: array<int, array<string, int|float|string>>, layoutRegions: array<int, array<string, mixed>>}
     */
    private function createVisualization(
        array $positionedText,
        array $details,
        string $text,
        int $pageNumber,
        array $marginArtifacts = [],
    ): array {
        $mediaBox = $details['MediaBox'] ?? null;
        $rotation = (int)($details['Rotate'] ?? 0);
        if (!is_array($mediaBox) || count($mediaBox) < 4 || $rotation % 360 !== 0) {
            return [
                'annotatedText' => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'regions' => [],
                'layoutRegions' => [],
            ];
        }

        $xOrigin = (float)$mediaBox[0];
        $yOrigin = (float)$mediaBox[1];
        $pageWidth = max(1.0, (float)$mediaBox[2] - $xOrigin);
        $pageHeight = max(1.0, (float)$mediaBox[3] - $yOrigin);
        $regions = [];

        foreach ($this->positionedTextReader->read($positionedText) as $row) {
            foreach ($row['parts'] as $part) {
                // Keep the original PDF text objects separate here. The
                // reading-order analyzer may deliberately rearrange columns,
                // so a row-wide merged segment would no longer occur verbatim
                // in the extracted text and could not be linked reliably.
                foreach ($part['atoms'] ?? [] as $atom) {
                    $fontSize = (float)($atom['fontSize'] ?? 10.0);
                    $regionText = $this->textNormalizer->normalize((string)($atom['text'] ?? ''));
                    if ($regionText === '' || preg_match('/[\p{L}\p{N}]/u', $regionText) !== 1) {
                        continue;
                    }
                    $baseline = (float)($atom['y'] ?? $row['y']);
                    $horizontalPadding = $fontSize * 0.25;
                    $estimatedWidth = max(
                        $fontSize * 0.25,
                        mb_strlen($regionText) * $fontSize * 0.58,
                    );
                    $left = $this->percentage(
                        (float)$atom['x'] - $xOrigin - $horizontalPadding,
                        $pageWidth,
                    );
                    $top = $this->percentage(
                        $pageHeight - (($baseline - $yOrigin) + $fontSize),
                        $pageHeight,
                    );
                    $width = max(0.35, $this->percentage(
                        $estimatedWidth + $horizontalPadding * 2,
                        $pageWidth,
                    ));
                    $height = max(0.6, $this->percentage($fontSize * 1.25, $pageHeight));
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

        $layoutRegions = [
            ...$this->layoutAnalyzer->diagnoseRegions($positionedText),
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

        return [
            'annotatedText' => $this->annotateText($text, $regions),
            'regions' => $regions,
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
        $matches = [];
        $occupied = [];
        usort($regions, static fn (array $left, array $right): int =>
            strlen((string)$right['text']) <=> strlen((string)$left['text'])
        );
        foreach ($regions as $region) {
            foreach ($this->matchingNeedles((string)$region['text']) as $needle) {
                foreach ($this->findOccurrences($text, $needle) as $offset) {
                    $length = strlen($needle);
                    if ($this->overlapsMatch($offset, $length, $occupied)) {
                        continue;
                    }
                    $matches[] = [
                        'offset' => $offset,
                        'length' => $length,
                        'region' => $region,
                    ];
                    $occupied[] = ['offset' => $offset, 'length' => $length];
                    continue 3;
                }
            }
        }
        usort($matches, static fn (array $left, array $right): int =>
            $left['offset'] <=> $right['offset'] ?: $right['length'] <=> $left['length']
        );

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
