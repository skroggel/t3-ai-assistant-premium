<?php
declare(strict_types=1);

defined('TYPO3') or die('Access denied.');

call_user_func(
    static function (string $extensionKey): void {
        \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
            $extensionKey,
            'Configuration/TypoScript',
            'AI Assistant Premium'
        );
    },
    'ai_assistant_premium'
);
