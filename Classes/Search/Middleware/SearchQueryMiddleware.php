<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
namespace Madj2k\AiAssistantPremium\Search\Middleware;

use Madj2k\AiAssistantPremium\Search\Configuration\SearchIntegration;
use Madj2k\AiAssistantPremium\Search\Configuration\SearchIntegrationRegistry;
use Madj2k\AiAssistantPremium\Search\Request\NestedParameterAccessor;
use Madj2k\AiAssistantPremium\Search\Service\QueryOptimizer;
use Madj2k\AiAssistantPremium\Search\Service\SearchStateService;
use Madj2k\AiAssistantPremium\License\LicenseService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Uri;

/**

 */
/**
 * Class SearchQueryMiddleware
 *
 * Optionally optimizes a native search-engine query before the engine runs.
 *
 * The middleware responds with a redirect containing the effective query. The
 * redirected request is then handled normally by ke_search, Solr or another
 * declaratively configured search integration.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
final readonly class SearchQueryMiddleware implements MiddlewareInterface
{
    /**
     *
     */
    public const CONTROL_PARAMETER = 'tx_aiassistantpremium_search';
    public const STATE_PARAMETER = 'ai';


    /**
     * @param \Madj2k\AiAssistantPremium\Search\Configuration\SearchIntegrationRegistry $integrationRegistry
     * @param \Madj2k\AiAssistantPremium\Search\Request\NestedParameterAccessor $parameterAccessor
     * @param \Madj2k\AiAssistantPremium\Search\Service\QueryOptimizer $queryOptimizer
     * @param \Madj2k\AiAssistantPremium\Search\Service\SearchStateService $searchStateService
     */
    public function __construct(
        private SearchIntegrationRegistry $integrationRegistry,
        private NestedParameterAccessor $parameterAccessor,
        private QueryOptimizer $queryOptimizer,
        private SearchStateService $searchStateService,
        private LicenseService $licenseService,
    ) {
    }


    /**
     * Optimizes a submitted search request and redirects to its result page.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @param \Psr\Http\Server\RequestHandlerInterface $handler
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \JsonException
     * @throws \Random\RandomException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // restore state parameter (ai=...) to real parameters
        $request = $this->restoreState($request);

        $control = $this->getControlParameters($request);
        if ($control === [] || (bool)($control['processed'] ?? false)) {
            return $handler->handle($request);
        }

        if (!$this->licenseService->isValid()) {
            return $handler->handle($request);
        }

        $profileUid = max(0, (int)($control['optimizerProfile'] ?? 0));
        $integration = $this->integrationRegistry->get((string)($control['integration'] ?? ''));
        if (!$integration instanceof SearchIntegration) {
            return $handler->handle($request);
        }

        $requestParameters = $this->getRequestParameters($request);
        $nativeQuery = $this->parameterAccessor->get($requestParameters, $integration->queryParameterPath);
        if (!is_scalar($nativeQuery) || trim((string)$nativeQuery) === '') {
            return $handler->handle($request);
        }
        $query = trim((string)$nativeQuery);

        $chatIdentifier = $this->normalizeChatIdentifier((string)($control['chatIdentifier'] ?? ''));
        $effectiveQuery = $this->queryOptimizer->optimize(
            query: $query,
            profileUid: $profileUid,
            chatIdentifier: $chatIdentifier,
            integration: $integration->identifier,
            request: $request,
        );

        $state = $this->searchStateService->encode([
            'processed' => 1,
            'integration' => $integration->identifier,
            'chatIdentifier' => $chatIdentifier,
            'originalQuery' => $query,
            'effectiveQuery' => $effectiveQuery,
        ]);

        $redirectUri = $this->buildRedirectUri(
            request: $request,
            requestParameters: $requestParameters,
            integration: $integration,
            effectiveQuery: $effectiveQuery,
            state: $state,
        );

        return new RedirectResponse((string)$redirectUri, 303);
    }


    /**
     * Restores an encrypted AI state into the PSR-7 request so all downstream
     * plugins can read tx_aiassistantpremium_search as usual.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return \Psr\Http\Message\ServerRequestInterface
     */
    private function restoreState(ServerRequestInterface $request): ServerRequestInterface
    {
        $state = $this->getStateParameter($request);
        if ($state === null || $state === '') {
            return $request;
        }

        try {
            $control = $this->searchStateService->decode($state);
        } catch (\Throwable) {
            return $request;
        }

        if ($control === []) {
            return $request;
        }

        if (strtoupper($request->getMethod()) === 'POST') {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $body[self::CONTROL_PARAMETER] = array_replace(
                is_array($body[self::CONTROL_PARAMETER] ?? null) ? $body[self::CONTROL_PARAMETER] : [],
                $control,
            );
            $request = $request->withParsedBody($body);
        } else {
            $queryParameters = $request->getQueryParams();
            $queryParameters[self::CONTROL_PARAMETER] = array_replace(
                is_array($queryParameters[self::CONTROL_PARAMETER] ?? null) ? $queryParameters[self::CONTROL_PARAMETER] : [],
                $control,
            );
            $request = $request->withQueryParams($queryParameters);
        }

        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }


    /**
     * Returns the compact encrypted AI state from GET or POST.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return string|null
     */
    private function getStateParameter(ServerRequestInterface $request): ?string
    {
        $queryState = $request->getQueryParams()[self::STATE_PARAMETER] ?? null;
        $body = $request->getParsedBody();
        $bodyState = is_array($body) ? ($body[self::STATE_PARAMETER] ?? null) : null;
        $state = $bodyState ?? $queryState;

        return is_scalar($state) ? trim((string)$state) : null;
    }


    /**
     * Returns the generic search control payload from body or query parameters.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return array<string,mixed>
     */
    private function getControlParameters(ServerRequestInterface $request): array
    {
        $queryControl = $request->getQueryParams()[self::CONTROL_PARAMETER] ?? [];
        $body = $request->getParsedBody();
        $bodyControl = is_array($body) ? ($body[self::CONTROL_PARAMETER] ?? []) : [];

        return array_replace(
            is_array($queryControl) ? $queryControl : [],
            is_array($bodyControl) ? $bodyControl : [],
        );
    }


    /**
     * Merges query-string and request-body values with body values taking precedence.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return array<string,mixed>
     */
    private function getRequestParameters(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return array_replace_recursive(
            $request->getQueryParams(),
            is_array($body) ? $body : [],
        );
    }


    /**
     * Builds a same-origin result URI containing engine parameters and AI metadata.
     *
     * @param array<string,mixed> $requestParameters
     */
    private function buildRedirectUri(
        ServerRequestInterface $request,
        array $requestParameters,
        SearchIntegration $integration,
        string $effectiveQuery,
        string $state,
    ): Uri {

        $targetUri = new Uri((string)$request->getUri());
        parse_str($targetUri->getQuery(), $targetParameters);

        // get the request namespace owned by the search engine
        $rootParameter = $integration->getRootParameter();

        if ($rootParameter !== '') {
            $engineParameters = is_array($requestParameters[$rootParameter] ?? null)
                ? $requestParameters[$rootParameter]
                : [];
            if ($engineParameters !== []) {
                $targetParameters[$rootParameter] = $engineParameters;
            }
        }

        $targetParameters = $this->parameterAccessor->set(
            $targetParameters,
            $integration->queryParameterPath,
            $effectiveQuery,
        );

        $targetParameters = $this->parameterAccessor->remove(
            $targetParameters,
            $integration->pageParameterPath,
        );

        unset($targetParameters[self::CONTROL_PARAMETER]);
        $targetParameters[self::STATE_PARAMETER] = $state;

        return $targetUri->withQuery(http_build_query(
            $targetParameters,
            '',
            '&',
            PHP_QUERY_RFC3986,
        ));
    }


    /**
     * Returns a stable identifier for optimization and summary requests.
     *
     * @param string $chatIdentifier
     * @return string
     */
    private function normalizeChatIdentifier(string $chatIdentifier): string
    {
        $chatIdentifier = preg_replace('/[^a-zA-Z0-9:_-]/', '', trim($chatIdentifier)) ?? '';
        if ($chatIdentifier !== '') {
            return mb_substr($chatIdentifier, 0, 128);
        }

        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return str_replace('.', '', uniqid('search', true));
        }
    }
}
