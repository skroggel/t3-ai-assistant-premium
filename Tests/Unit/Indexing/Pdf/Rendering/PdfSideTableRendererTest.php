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

namespace Madj2k\AiAssistantPremium\Tests\Unit\Indexing\Pdf\Rendering;

use Madj2k\AiAssistantPremium\Indexing\Pdf\Rendering\PdfSideTableRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Class PdfSideTableRendererTest
 *
 * Verifies reconstruction of compact PDF key/value tables.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final class PdfSideTableRendererTest extends TestCase
{
    /**
     * Verifies that wrapped value fragments remain attached to the nearest key row.
     */
    public function testAssignsWrappedValuesToClosestKeyBaseline(): void
    {
        $visualRowList = [
            $this->visualRow(100.0, [
                $this->visualPart(10.0, 'Key A'),
                $this->visualPart(100.0, 'Value A'),
            ]),
            $this->visualRow(90.0, [
                $this->visualPart(100.0, 'continued'),
            ]),
            $this->visualRow(60.0, [
                $this->visualPart(10.0, 'Key B'),
                $this->visualPart(100.0, 'Value B'),
            ]),
        ];

        $result = (new PdfSideTableRenderer())->render($visualRowList);

        self::assertSame("Key A | Value A continued\nKey B | Value B", $result);
    }


    /**
     * Creates one visual row for side-table rendering tests.
     *
     * @param float $y Vertical baseline.
     * @param array<int, array{xMin: float, xMax: float, text: string}> $visualPartList Visual row parts.
     * @return array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>} Visual row.
     */
    private function visualRow(float $y, array $visualPartList): array
    {
        return ['y' => $y, 'parts' => $visualPartList];
    }


    /**
     * Creates one visual part for side-table rendering tests.
     *
     * @param float $xMin Horizontal start.
     * @param string $text Part text.
     * @return array{xMin: float, xMax: float, text: string} Visual part.
     */
    private function visualPart(float $xMin, string $text): array
    {
        return ['xMin' => $xMin, 'xMax' => $xMin + 40.0, 'text' => $text];
    }
}
