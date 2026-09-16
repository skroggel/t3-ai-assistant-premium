<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Madj2k\AiAssistantPremium\Controller;

use Madj2k\AiAssistant\Backend\Form\BackendFormTokenService;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfDiagnosticService;
use Madj2k\AiAssistantPremium\License\LicenseService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Read-only backend diagnostics for Premium PDF extraction.
 */
#[AsController]
final readonly class PdfDiagnosticsController
{
    private const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly BackendFormTokenService $formTokenService,
        private readonly PdfDiagnosticService $diagnosticService,
        private readonly LicenseService $licenseService,
    ) {
    }

    public function handleRequest(ServerRequestInterface $backendRequest): ResponseInterface
    {
        $formProtection = $this->formTokenService->createFormProtection($backendRequest);
        $result = null;
        $error = '';

        if (strtoupper($backendRequest->getMethod()) === 'POST') {
            if (!$this->formTokenService->validate($backendRequest, $formProtection)) {
                $error = 'Invalid form token. Please reload the module and try again.';
            } elseif (!$this->licenseService->isValid()) {
                $error = 'PDF diagnostics require a valid Premium license.';
            } else {
                try {
                    $upload = $this->resolveUpload($backendRequest);
                    $result = $this->diagnosticService->inspect(
                        $this->resolveTemporaryPath($upload),
                        $upload->getClientFilename() ?: 'uploaded.pdf',
                    );
                } catch (\Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($backendRequest);
        $moduleTemplate->assignMultiple([
            'formToken' => $this->formTokenService->generate($formProtection),
            'licenseValid' => $this->licenseService->isValid(),
            'diagnosticResult' => $result,
            'diagnosticError' => $error,
            'maximumUploadMegabytes' => (int)(self::MAX_UPLOAD_BYTES / 1024 / 1024),
        ]);

        GeneralUtility::makeInstance(AssetCollector::class)
            ->addStyleSheet('aiassistant-backend', 'EXT:ai_assistant/Resources/Public/Styles/Backend.css')
            ->addStyleSheet(
                'aiassistant-premium-pdf-diagnostics',
                'EXT:ai_assistant_premium/Resources/Public/Styles/PdfDiagnostics.css',
            )
            ->addJavaScript(
                'aiassistant-premium-pdf-diagnostics',
                'EXT:ai_assistant_premium/Resources/Public/JavaScript/PdfDiagnostics.js',
                ['defer' => 'defer'],
            );

        return $moduleTemplate->renderResponse('PdfDiagnostics/Index');
    }

    private function resolveUpload(ServerRequestInterface $request): UploadedFileInterface
    {
        $upload = $request->getUploadedFiles()['pdfFile'] ?? null;
        if (!$upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Please select a readable PDF file.', 1789570671);
        }
        if (($upload->getSize() ?? 0) > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('The PDF exceeds the maximum upload size.', 1789570672);
        }
        if (strtolower((string)pathinfo($upload->getClientFilename() ?? '', PATHINFO_EXTENSION)) !== 'pdf') {
            throw new \RuntimeException('Only PDF files can be inspected.', 1789570673);
        }

        return $upload;
    }

    private function resolveTemporaryPath(UploadedFileInterface $upload): string
    {
        $path = $upload->getStream()->getMetadata('uri');
        if (!is_string($path) || $path === '' || !is_file($path)) {
            throw new \RuntimeException('The uploaded PDF is not available for inspection.', 1789570674);
        }

        return $path;
    }
}
