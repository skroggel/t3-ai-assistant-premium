<?php
declare(strict_types=1);

use Madj2k\AiAssistantPremium\Backend\Controller\PdfDiagnosticsController;

return [
    'web_aiassistant_pdf_diagnostics' => [
        'parent' => 'web',
        'position' => ['after' => 'web_aiassistant'],
        'access' => 'user,group',
        'workspaces' => 'live',
        'iconIdentifier' => 'aiassistant-module',
        'labels' => 'LLL:EXT:ai_assistant_premium/Resources/Private/Language/locallang_pdf_diagnostics.xlf',
        'routes' => [
            '_default' => [
                'target' => PdfDiagnosticsController::class . '::handleRequest',
            ],
        ],
    ],
];
