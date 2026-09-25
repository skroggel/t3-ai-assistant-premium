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

namespace Madj2k\AiAssistantPremium\Backend\Controller;

use Madj2k\AiAssistant\Backend\Form\BackendFormTokenService;
use Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig;
use Madj2k\AiAssistant\Indexing\Domain\Repository\IndexerConfigRepository;
use Madj2k\AiAssistantPremium\Backend\PdfDiagnostics\PdfDiagnosticService;
use Madj2k\AiAssistantPremium\Backend\PdfDiagnostics\PdfQdrantChunkPreviewService;
use Madj2k\AiAssistantPremium\License\LicenseService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Class PdfDiagnosticsController
 *
 * Provides read-only backend diagnostics for PDF extraction, layout analysis and chunking.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
#[AsController]
final readonly class PdfDiagnosticsController
{
    private const int MAX_UPLOAD_BYTES = 25 * 1024 * 1024;
    private const string LLL_PREFIX =
        'LLL:EXT:ai_assistant_premium/Resources/Private/Language/locallang_pdf_diagnostics.xlf:';

    /**
     * Constructor.
     *
     * @param ModuleTemplateFactory $moduleTemplateFactory Factory for TYPO3 backend module views.
     * @param BackendFormTokenService $formTokenService Service for backend CSRF token handling.
     * @param PdfDiagnosticService $diagnosticService Service that builds PDF extraction diagnostics.
     * @param PdfQdrantChunkPreviewService $chunkPreviewService Service that adds production-equivalent chunk previews.
     * @param IndexerConfigRepository $indexerConfigRepository Repository for selectable chunking configurations.
     * @param LicenseService $licenseService Premium license validator.
     */
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private BackendFormTokenService $formTokenService,
        private PdfDiagnosticService $diagnosticService,
        private PdfQdrantChunkPreviewService $chunkPreviewService,
        private IndexerConfigRepository $indexerConfigRepository,
        private LicenseService $licenseService,
    ) {
    }


    /**
     * Renders the diagnostics module and processes an optional PDF upload.
     *
     * Upload and parsing errors are converted into a user-visible module message.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $backendRequest Current TYPO3 backend request.
     * @return \Psr\Http\Message\ResponseInterface Rendered backend-module response.
     * @throws \InvalidArgumentException If TYPO3 cannot prepare the requested module template.
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException If the TYPO3 runtime cache is unavailable.
     */
    public function handleRequest(ServerRequestInterface $backendRequest): ResponseInterface
    {
        $formProtection = $this->formTokenService->createFormProtection($backendRequest);
        $result = null;
        $error = '';
        [$chunkingOptions, $selectedChunkingUid, $chunkingConfiguration] =
            $this->resolveChunkingConfiguration($backendRequest);

        if (strtoupper($backendRequest->getMethod()) === 'POST') {
            if (!$this->formTokenService->validate($backendRequest, $formProtection)) {
                $error = $this->translate('error.invalidFormToken');
            } elseif (!$this->licenseService->isValid()) {
                $error = $this->translate('error.licenseRequired');
            } else {
                try {
                    $upload = $this->resolveUpload($backendRequest);
                    $result = $this->chunkPreviewService->addChunkPreview(
                        $this->diagnosticService->inspect(
                            $this->resolveTemporaryPath($upload),
                            $upload->getClientFilename() ?: 'uploaded.pdf',
                        ),
                        $chunkingConfiguration,
                    );
                } catch (\Throwable $exception) {
                    $error = $this->translate('error.inspectionFailed', [$exception->getMessage()]);
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
            'chunkingConfigurationOptions' => $chunkingOptions,
            'selectedChunkingConfigurationUid' => $selectedChunkingUid,
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


    /**
     * Resolves and validates the uploaded PDF from the backend request.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Current backend request.
     * @return \Psr\Http\Message\UploadedFileInterface Validated PDF upload.
     * @throws \RuntimeException If the upload is missing, invalid, oversized or not a PDF.
     */
    private function resolveUpload(ServerRequestInterface $request): UploadedFileInterface
    {
        $upload = $request->getUploadedFiles()['pdfFile'] ?? null;
        if (!$upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->translate('error.uploadMissing'), 1789570671);
        }
        if (($upload->getSize() ?? 0) > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException($this->translate('error.uploadTooLarge'), 1789570672);
        }
        if (strtolower((string)pathinfo($upload->getClientFilename() ?? '', PATHINFO_EXTENSION)) !== 'pdf') {
            throw new \RuntimeException($this->translate('error.uploadType'), 1789570673);
        }

        return $upload;
    }


    /**
     * Resolves the readable temporary file path of an uploaded PDF.
     *
     * @param \Psr\Http\Message\UploadedFileInterface $upload Validated upload.
     * @return string Absolute temporary file path.
     * @throws \RuntimeException If no readable temporary file is available.
     */
    private function resolveTemporaryPath(UploadedFileInterface $upload): string
    {
        $path = $upload->getStream()->getMetadata('uri');
        if (!is_string($path) || $path === '' || !is_file($path)) {
            throw new \RuntimeException($this->translate('error.uploadUnavailable'), 1789570674);
        }

        return $path;
    }


    /**
     * Resolves selectable file-indexer configurations and the active chunk settings.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Current backend request.
     * @return array{0: array<int, array{uid: int, label: string}>, 1: int, 2: array{uid: int, title: string, chunkSize: int, chunkOverlap: int, maxChunks: int, minChunkChars: int}} Configuration options, selected UID and effective settings.
     */
    private function resolveChunkingConfiguration(ServerRequestInterface $request): array
    {
        $configurations = [];
        foreach ($this->indexerConfigRepository->findByIndexerIdentifier('aiassistant.indexer.file') as $configuration) {
            $configurations[(int)$configuration->getUid()] = $configuration;
        }

        $requestedUid = (int)(((array)$request->getParsedBody())['indexerConfigUid'] ?? 0);
        $selectedUid = isset($configurations[$requestedUid])
            ? $requestedUid
            : (int)(array_key_first($configurations) ?? 0);
        $selected = $configurations[$selectedUid] ?? null;

        if (!$selected instanceof IndexerConfig) {
            return [[[
                'uid' => 0,
                'label' => $this->translate('configuration.serviceDefaultsOption'),
            ]], 0, [
                'uid' => 0,
                'title' => $this->translate('configuration.serviceDefaults'),
                'chunkSize' => 0,
                'chunkOverlap' => 0,
                'maxChunks' => 0,
                'minChunkChars' => 0,
            ]];
        }

        $options = [];
        foreach ($configurations as $uid => $configuration) {
            $options[] = [
                'uid' => $uid,
                'label' => sprintf('%s (#%d)', $configuration->getTitle(), $uid),
            ];
        }

        return [$options, $selectedUid, [
            'uid' => $selectedUid,
            'title' => $selected->getTitle(),
            'chunkSize' => $selected->getChunkSize(),
            'chunkOverlap' => $selected->getChunkOverlap(),
            'maxChunks' => $selected->getMaxChunks(),
            'minChunkChars' => $selected->getMinChunkChars(),
        ]];
    }


    /**
     * Resolves a PDF-diagnostics label in the active TYPO3 backend language.
     *
     * @param string $key Translation key without the language-file prefix.
     * @param array<int, mixed> $arguments Optional sprintf-compatible translation arguments.
     * @return string Translated label or the key when no translation is available.
     */
    private function translate(string $key, array $arguments = []): string
    {
        return LocalizationUtility::translate(self::LLL_PREFIX . $key, null, $arguments) ?? $key;
    }
}
