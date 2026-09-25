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


namespace Madj2k\AiAssistantPremium\Controller;

use Madj2k\AiAssistant\Assistant\Domain\Repository\AssistantProfileRepository;
use Madj2k\AiAssistant\Controller\AbstractController;
use Madj2k\AiAssistantPremium\Search\Middleware\SearchQueryMiddleware;
use Madj2k\AiAssistantPremium\License\LicenseService;
use Psr\Http\Message\ResponseInterface;

/**
 * Renders the native-search enhancement metadata and result summary shell.
 *
 * Query optimization itself belongs to the PSR-15 middleware and search
 * execution remains the responsibility of the configured search engine.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */

final class SearchController extends AbstractController
{
    public function __construct(
        AssistantProfileRepository $assistantProfileRepository,
        private readonly LicenseService $licenseService,
    ) {
        parent::__construct($assistantProfileRepository);
    }

    /**
     * Renders metadata used to enhance an existing native search form.
     */
    public function indexAction(): ResponseInterface
    {
        if (!$this->licenseService->isValid()) {
            return $this->htmlResponse('');
        }

        $metadata = $this->getSearchMetadata();
        $this->view->assignMultiple([
            'chatIdentifier' => $this->resolveChatIdentifier($metadata),
        ]);

        return $this->htmlResponse();
    }

    /**
     * Renders the summary form consumed after search results are available.
     */
    public function searchSummaryAction(): ResponseInterface
    {
        if (!$this->licenseService->isValid()) {
            return $this->htmlResponse('');
        }

        $metadata = $this->getSearchMetadata();
        $this->view->assignMultiple([
            'chatIdentifier' => $this->resolveChatIdentifier($metadata),
            'searchString' => trim((string)($metadata['originalQuery'] ?? '')),
            'startTimestamp' => time(),
            'settingsJson' => $this->jsonEncodeSettings($this->settings),
        ]);

        return $this->htmlResponse();
    }

    /**
     * Returns metadata placed on the redirected request by the middleware.
     *
     * @return array<string,mixed>
     */
    private function getSearchMetadata(): array
    {
        $request = $this->resolveServerRequest();
        $metadata = $request?->getQueryParams()[SearchQueryMiddleware::CONTROL_PARAMETER] ?? [];

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * Reuses the search identifier or creates one for a fresh form.
     *
     * @param array<string,mixed> $metadata
     */
    private function resolveChatIdentifier(array $metadata): string
    {
        $chatIdentifier = trim((string)($metadata['chatIdentifier'] ?? ''));
        return $chatIdentifier !== '' ? $chatIdentifier : $this->createChatIdentifier();
    }
}
