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


namespace Madj2k\AiAssistantPremium\Backend\Configuration;

use Madj2k\AiAssistant\Backend\Configuration\BackendRegistryFieldContributorInterface;
use Madj2k\AiAssistantPremium\Indexing\Adapter\PdfAdapter;

/**
 * PdfRegistryFieldContributor
 *
 * Contributes PDF settings to the central backend configuration.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
final class PdfRegistryFieldContributor implements BackendRegistryFieldContributorInterface
{
    private const LLL_PREFIX = 'LLL:EXT:ai_assistant_premium/Resources/Private/Language/locallang_be.xlf:';

    /**
     * @inheritDoc
     */
    public function getFields(): array
    {
        return [
            [
                'key' => PdfAdapter::LINK_TO_MATCHED_PAGE_CONFIGURATION_KEY,
                'label' => self::LLL_PREFIX . 'configuration.pdf.linkToMatchedPage.label',
                'description' => self::LLL_PREFIX . 'configuration.pdf.linkToMatchedPage.description',
                'type' => 'select',
                'default' => '0',
                'maxLength' => 1,
                'allowDelete' => false,
                'options' => [
                    [
                        'value' => '0',
                        'label' => self::LLL_PREFIX
                            . 'configuration.pdf.linkToMatchedPage.option.completePdf',
                    ],
                    [
                        'value' => '1',
                        'label' => self::LLL_PREFIX
                            . 'configuration.pdf.linkToMatchedPage.option.matchedPage',
                    ],
                ],
            ],
        ];
    }
}
