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
 * Finds sizeable vector artwork in areas that contain no extractable text.
 *
 * This is intentionally a conservative diagnostic hint. It neither performs
 * OCR nor changes the text handed to the indexer.
 */
final readonly class PdfVectorArtworkDetector
{
    private const PAGE_MARGIN_RATIO = 0.05;
    private const MIN_GAP_HEIGHT_RATIO = 0.14;
    private const MIN_ARTWORK_WIDTH_RATIO = 0.18;
    private const MIN_ARTWORK_HEIGHT_RATIO = 0.08;
    private const MIN_PAINTED_PATHS = 14;
    private const ARTWORK_MARGIN_RATIO = 0.075;

    public function __construct(
        private PdfPositionedTextReader $positionedTextReader = new PdfPositionedTextReader(),
    ) {
    }

    /**
     * @param array<int, array<int, mixed>> $positionedText
     * @param array<string, mixed> $details
     * @return array<int, array<string, mixed>>
     */
    public function detect(array $positionedText, array $details, string $content): array
    {
        $mediaBox = $details['MediaBox'] ?? null;
        if (!is_array($mediaBox) || count($mediaBox) < 4 || trim($content) === '') {
            return [];
        }

        $xOrigin = (float)$mediaBox[0];
        $yOrigin = (float)$mediaBox[1];
        $pageWidth = max(1.0, (float)$mediaBox[2] - $xOrigin);
        $pageHeight = max(1.0, (float)$mediaBox[3] - $yOrigin);
        $paintedPaths = array_values(array_filter(
            $this->collectPaintedPaths($content),
            static function (array $path) use ($pageWidth, $pageHeight): bool {
                $width = $path['xMax'] - $path['xMin'];
                $height = $path['yTop'] - $path['yBottom'];
                // Outlined letters and icons are composed of compact painted
                // paths. Disregard page backgrounds, image clipping paths,
                // hairlines and other broad layout decoration.
                return $width >= $pageWidth * 0.001
                    && $height >= $pageHeight * 0.002
                    && $width <= $pageWidth * 0.3
                    && $height <= $pageHeight * 0.1;
            },
        ));
        if (count($paintedPaths) < self::MIN_PAINTED_PATHS) {
            return [];
        }

        $artifacts = [];
        foreach ($this->findTextFreeBands($positionedText, $yOrigin, $pageHeight) as $band) {
            $paths = array_values(array_filter(
                $paintedPaths,
                static fn (array $path): bool => ($path['yBottom'] + $path['yTop']) / 2.0 >= $band['yBottom']
                    && ($path['yBottom'] + $path['yTop']) / 2.0 <= $band['yTop'],
            ));
            if (count($paths) < self::MIN_PAINTED_PATHS) {
                continue;
            }

            $bounds = $this->unionBounds($paths);
            if ($bounds === null
                || $bounds['xMax'] - $bounds['xMin'] < $pageWidth * self::MIN_ARTWORK_WIDTH_RATIO
                || $bounds['yTop'] - $bounds['yBottom'] < $pageHeight * self::MIN_ARTWORK_HEIGHT_RATIO
                || $bounds['yBottom'] <= $yOrigin + $pageHeight * self::ARTWORK_MARGIN_RATIO
                || $bounds['yTop'] >= $yOrigin + $pageHeight * (1.0 - self::ARTWORK_MARGIN_RATIO)
            ) {
                continue;
            }

            $artifacts[] = [
                'type' => 'vector-artwork',
                'label' => 'Vektorgrafik',
                'text' => 'Kein extrahierbarer Text; für diesen Inhalt wäre OCR erforderlich.',
                'columnCount' => 0,
                'tableRowCount' => 0,
                'excluded' => true,
                'xMin' => max($xOrigin, $bounds['xMin']),
                'xMax' => min($xOrigin + $pageWidth, $bounds['xMax']),
                'yBottom' => max($band['yBottom'], $bounds['yBottom']),
                'yTop' => min($band['yTop'], $bounds['yTop']),
            ];
        }

        return $artifacts;
    }

    /**
     * @param array<int, array<int, mixed>> $positionedText
     * @return array<int, array{yBottom: float, yTop: float}>
     */
    private function findTextFreeBands(array $positionedText, float $yOrigin, float $pageHeight): array
    {
        $contentBottom = $yOrigin + $pageHeight * self::PAGE_MARGIN_RATIO;
        $contentTop = $yOrigin + $pageHeight * (1.0 - self::PAGE_MARGIN_RATIO);
        $padding = max(4.0, $pageHeight * 0.008);
        $occupied = [];
        foreach ($this->positionedTextReader->read($positionedText) as $row) {
            $fontSize = 10.0;
            foreach ($row['parts'] as $part) {
                foreach ($part['atoms'] ?? [] as $atom) {
                    $fontSize = max($fontSize, (float)($atom['verticalScale'] ?? $atom['fontSize'] ?? 10.0));
                }
            }
            $occupied[] = [
                'yBottom' => max($contentBottom, (float)$row['y'] - $fontSize * 0.3 - $padding),
                'yTop' => min($contentTop, (float)$row['y'] + $fontSize + $padding),
            ];
        }
        usort($occupied, static fn (array $left, array $right): int => $left['yBottom'] <=> $right['yBottom']);

        $merged = [];
        foreach ($occupied as $interval) {
            if ($interval['yTop'] <= $interval['yBottom']) {
                continue;
            }
            $last = array_key_last($merged);
            if ($last !== null && $interval['yBottom'] <= $merged[$last]['yTop']) {
                $merged[$last]['yTop'] = max($merged[$last]['yTop'], $interval['yTop']);
                continue;
            }
            $merged[] = $interval;
        }

        $bands = [];
        $cursor = $contentBottom;
        foreach ($merged as $interval) {
            if ($interval['yBottom'] - $cursor >= $pageHeight * self::MIN_GAP_HEIGHT_RATIO) {
                $bands[] = ['yBottom' => $cursor, 'yTop' => $interval['yBottom']];
            }
            $cursor = max($cursor, $interval['yTop']);
        }
        if ($contentTop - $cursor >= $pageHeight * self::MIN_GAP_HEIGHT_RATIO) {
            $bands[] = ['yBottom' => $cursor, 'yTop' => $contentTop];
        }

        return $bands;
    }

    /** @return array<int, array{xMin: float, xMax: float, yBottom: float, yTop: float}> */
    private function collectPaintedPaths(string $content): array
    {
        // Text inside BT/ET is already covered by positioned extraction. Only
        // inspect drawing commands so outlined glyphs remain distinguishable.
        $drawingContent = preg_replace('/\bBT\b.*?\bET\b/s', ' ', $content) ?? $content;
        $drawingContent = preg_replace('/\bBI\b.*?\bEI\b/s', ' ', $drawingContent) ?? $drawingContent;
        preg_match_all(
            '/%(?:[^\r\n]*)|\((?:\\\\.|[^\\\\()])*\)|<(?!=<)[0-9A-Fa-f\s]*>|\/[^\s\[\]<>{}()%]+|[-+]?(?:\d*\.\d+|\d+\.?)(?:[Ee][-+]?\d+)?|[A-Za-z][A-Za-z0-9*\']*/',
            $drawingContent,
            $matches,
        );

        $matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $matrixStack = [];
        $operands = [];
        $pathPoints = [];
        $paths = [];
        foreach ($matches[0] ?? [] as $token) {
            if ($token === '' || $token[0] === '%' || is_numeric($token)) {
                if (is_numeric($token)) {
                    $operands[] = (float)$token;
                }
                continue;
            }
            if ($token[0] === '(' || $token[0] === '<' || $token[0] === '/') {
                continue;
            }

            switch ($token) {
                case 'q':
                    $matrixStack[] = $matrix;
                    break;
                case 'Q':
                    $matrix = array_pop($matrixStack) ?? [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
                    $pathPoints = [];
                    break;
                case 'cm':
                    if (count($operands) >= 6) {
                        $matrix = $this->composeMatrices($matrix, array_slice($operands, -6));
                    }
                    break;
                case 'm':
                case 'l':
                    $this->appendPoint($pathPoints, $operands, $matrix, 2);
                    break;
                case 'c':
                    $this->appendPoint($pathPoints, $operands, $matrix, 6, 0);
                    $this->appendPoint($pathPoints, $operands, $matrix, 6, 2);
                    $this->appendPoint($pathPoints, $operands, $matrix, 6, 4);
                    break;
                case 'v':
                case 'y':
                    $this->appendPoint($pathPoints, $operands, $matrix, 4, 0);
                    $this->appendPoint($pathPoints, $operands, $matrix, 4, 2);
                    break;
                case 're':
                    if (count($operands) >= 4) {
                        [$x, $y, $width, $height] = array_slice($operands, -4);
                        foreach ([[$x, $y], [$x + $width, $y], [$x, $y + $height], [$x + $width, $y + $height]] as $point) {
                            $pathPoints[] = $this->transformPoint($point[0], $point[1], $matrix);
                        }
                    }
                    break;
                case 'S':
                case 's':
                case 'f':
                case 'F':
                case 'f*':
                case 'B':
                case 'B*':
                case 'b':
                case 'b*':
                    $bounds = $this->boundsFromPoints($pathPoints);
                    if ($bounds !== null) {
                        $paths[] = $bounds;
                    }
                    $pathPoints = [];
                    break;
                case 'n':
                    $pathPoints = [];
                    break;
            }
            $operands = [];
        }

        return $paths;
    }

    /**
     * @param array<int, array{0: float, 1: float}> $points
     * @param array<int, float> $operands
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     */
    private function appendPoint(array &$points, array $operands, array $matrix, int $required, int $offset = 0): void
    {
        if (count($operands) < $required) {
            return;
        }
        $values = array_slice($operands, -$required);
        $points[] = $this->transformPoint($values[$offset], $values[$offset + 1], $matrix);
    }

    /**
     * @param array<int, float> $current
     * @param array<int, float> $next
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private function composeMatrices(array $current, array $next): array
    {
        return [
            $current[0] * $next[0] + $current[2] * $next[1],
            $current[1] * $next[0] + $current[3] * $next[1],
            $current[0] * $next[2] + $current[2] * $next[3],
            $current[1] * $next[2] + $current[3] * $next[3],
            $current[0] * $next[4] + $current[2] * $next[5] + $current[4],
            $current[1] * $next[4] + $current[3] * $next[5] + $current[5],
        ];
    }

    /** @param array<int, float> $matrix */
    private function transformPoint(float $x, float $y, array $matrix): array
    {
        return [
            $matrix[0] * $x + $matrix[2] * $y + $matrix[4],
            $matrix[1] * $x + $matrix[3] * $y + $matrix[5],
        ];
    }

    /**
     * @param array<int, array{0: float, 1: float}> $points
     * @return array{xMin: float, xMax: float, yBottom: float, yTop: float}|null
     */
    private function boundsFromPoints(array $points): ?array
    {
        if ($points === []) {
            return null;
        }
        $x = array_column($points, 0);
        $y = array_column($points, 1);
        return ['xMin' => min($x), 'xMax' => max($x), 'yBottom' => min($y), 'yTop' => max($y)];
    }

    /**
     * @param array<int, array{xMin: float, xMax: float, yBottom: float, yTop: float}> $bounds
     * @return array{xMin: float, xMax: float, yBottom: float, yTop: float}|null
     */
    private function unionBounds(array $bounds): ?array
    {
        if ($bounds === []) {
            return null;
        }
        return [
            'xMin' => min(array_column($bounds, 'xMin')),
            'xMax' => max(array_column($bounds, 'xMax')),
            'yBottom' => min(array_column($bounds, 'yBottom')),
            'yTop' => max(array_column($bounds, 'yTop')),
        ];
    }
}
