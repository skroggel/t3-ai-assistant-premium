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
        $pages = [];

        foreach ($document->getPages() as $index => $page) {
            $result = $this->pageTextExtractor->extract($page);
            $text = $this->textNormalizer->normalize($result->text);
            $warnings = $this->overlapDetector->detect($page->getDataTm());
            $visualization = $this->createVisualization(
                $page->getDataTm(),
                $page->getDetails(),
                $text,
                $index + 1,
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
                'previewDataUri' => $index < self::MAX_PREVIEW_PAGES
                    ? $this->renderPreview($path, $index)
                    : null,
                'warnings' => $warnings,
                'warningCount' => count($warnings),
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
     * @return array{annotatedText: string, regions: array<int, array<string, int|float|string>>}
     */
    private function createVisualization(
        array $positionedText,
        array $details,
        string $text,
        int $pageNumber,
    ): array {
        $mediaBox = $details['MediaBox'] ?? null;
        $rotation = (int)($details['Rotate'] ?? 0);
        if (!is_array($mediaBox) || count($mediaBox) < 4 || $rotation % 360 !== 0) {
            return [
                'annotatedText' => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'regions' => [],
            ];
        }

        $xOrigin = (float)$mediaBox[0];
        $yOrigin = (float)$mediaBox[1];
        $pageWidth = max(1.0, (float)$mediaBox[2] - $xOrigin);
        $pageHeight = max(1.0, (float)$mediaBox[3] - $yOrigin);
        $regions = [];

        foreach ($this->positionedTextReader->read($positionedText) as $row) {
            foreach ($row['parts'] as $part) {
                $atoms = $part['atoms'] ?? [];
                $fontSize = max(array_map(
                    static fn (array $atom): float => (float)($atom['fontSize'] ?? 10.0),
                    $atoms ?: [['fontSize' => 10.0]],
                ));
                $baseline = (float)$row['y'];
                $left = $this->percentage((float)$part['xMin'] - $xOrigin, $pageWidth);
                $top = $this->percentage(
                    $pageHeight - (($baseline - $yOrigin) + $fontSize),
                    $pageHeight,
                );
                $width = max(0.35, $this->percentage(
                    (float)$part['xMax'] - (float)$part['xMin'],
                    $pageWidth,
                ));
                $height = max(0.6, $this->percentage($fontSize * 1.25, $pageHeight));
                $number = count($regions) + 1;
                $regions[] = [
                    'id' => sprintf('page-%d-region-%d', $pageNumber, $number),
                    'number' => $number,
                    'text' => $this->textNormalizer->normalize((string)$part['text']),
                    'left' => round($left, 4),
                    'top' => round($top, 4),
                    'width' => round(min($width, 100.0 - $left), 4),
                    'height' => round(min($height, 100.0 - $top), 4),
                    'hue' => ($number * 47) % 360,
                ];
            }
        }

        return [
            'annotatedText' => $this->annotateText($text, $regions),
            'regions' => $regions,
        ];
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
        foreach ($regions as $region) {
            $needle = (string)$region['text'];
            if ($needle === '') {
                continue;
            }
            $offset = strpos($text, $needle);
            if ($offset === false) {
                continue;
            }
            $matches[] = [
                'offset' => $offset,
                'length' => strlen($needle),
                'region' => $region,
            ];
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
