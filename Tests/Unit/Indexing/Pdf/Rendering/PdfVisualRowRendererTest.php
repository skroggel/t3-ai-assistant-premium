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

use Madj2k\AiAssistantPremium\Indexing\Pdf\Rendering\PdfVisualRowRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Class PdfVisualRowRendererTest
 *
 * Verifies serialization of visual PDF rows and sequential text blocks.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final class PdfVisualRowRendererTest extends TestCase
{
    /**
     * Verifies that cell-oriented and prose-oriented rendering use different separators.
     */
    public function testRendersCellsAndPlainRowsWithDifferentSeparators(): void
    {
        $visualRowList = [[
            'y' => 100.0,
            'parts' => [
                ['xMin' => 10.0, 'xMax' => 40.0, 'text' => 'Left'],
                ['xMin' => 80.0, 'xMax' => 120.0, 'text' => 'Right'],
            ],
        ]];
        $subject = new PdfVisualRowRenderer();

        self::assertSame('Left | Right', $subject->renderRows($visualRowList));
        self::assertSame('Left Right', $subject->renderPlainRows($visualRowList));
    }


    /**
     * Verifies that explicit word hyphenation is repaired across sequential blocks.
     */
    public function testRepairsHyphenationAcrossSequentialBlocks(): void
    {
        $result = (new PdfVisualRowRenderer())->joinSequentialBlocks([
            'cross-',
            'column text',
        ]);

        self::assertSame('crosscolumn text', $result);
    }
}
