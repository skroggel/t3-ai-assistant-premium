<?php
declare(strict_types=1);


use Madj2k\AiAssistantPremium\Controller\SearchController;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die('Access denied.');

(static function (): void {
    ExtensionUtility::configurePlugin(
        'AiAssistantPremium',
        'Search',
        [
            SearchController::class => 'index,normalize',
        ],
        [
            SearchController::class => 'index,normalize',
        ],
        ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
    );

    ExtensionUtility::configurePlugin(
        'AiAssistantPremium',
        'SearchSummary',
        [
            SearchController::class => 'searchSummary',
        ],
        [
            SearchController::class => 'searchSummary',
        ],
        ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
    );

})();
