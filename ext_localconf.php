<?php
declare(strict_types=1);


use Madj2k\AiAssistantPremium\Controller\SearchController;
use Madj2k\AiAssistantPremium\Controller\JsonController;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die('Access denied.');

(static function (): void {
    $GLOBALS['TYPO3_CONF_VARS']['LOG']['Madj2k']['AiAssistantPremium']['writerConfiguration'] = [
        LogLevel::WARNING => [
            FileWriter::class => [
                'logFile' => Environment::getVarPath() . '/log/tx_aiassistant_premium.log',
            ],
        ],
    ];

    $GLOBALS['TYPO3_CONF_VARS']['LOG']['Madj2k']['AiAssistantPremium']['Indexing']['writerConfiguration'] = [
        LogLevel::WARNING => [
            FileWriter::class => [
                'logFile' => Environment::getVarPath() . '/log/tx_aiassistant_premium_indexing.log',
            ],
        ],
    ];

    ExtensionUtility::configurePlugin(
        'AiAssistantPremium',
        'Search',
        [
            SearchController::class => 'index',
            JsonController::class => 'assistant',
        ],
        [
            SearchController::class => 'index,normalize',
            JsonController::class => 'assistant',
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
