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


namespace Madj2k\AiAssistantPremium\Indexing\Connector;

use Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig;
use Madj2k\AiAssistantPremium\License\LicenseService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class ShopwareConnector
 *
 * Connects the indexing domain to the Shopware Admin API.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
readonly class ShopwareConnector implements ShopwareConnectorInterface
{
    /**
     * Request factory.
     *
     * @var \TYPO3\CMS\Core\Http\RequestFactory
     */
    private RequestFactory $requestFactory;


    /**
     * Logger.
     *
     * @var \Psr\Log\LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor.
     */
    public function __construct(
        private LicenseService $licenseService,
    ) {
        $this->requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }


    /**
     * Returns the connector identifier.
     *
     * @return string Connector identifier.
     */
    public function getIdentifier(): string
    {
        return 'aiassistant.connector.shopware';
    }


    /**
     * Returns the connector label.
     *
     * @return string Connector label.
     */
    public function getLabel(): string
    {
        return 'Shopware connector';
    }


    /**
     * Fetches an OAuth access token using client credentials.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return string Access token.
     */
    public function fetchAccessToken(IndexerConfig $configuration): string
    {
        $this->licenseService->requireValidLicense();

        $baseUrl = $this->resolveBaseUrl($configuration);
        $clientId = trim($configuration->getShopwareClientId());
        $clientSecret = trim($configuration->getShopwareApiKey());

        if ($baseUrl === '' || $clientId === '' || $clientSecret === '') {
            $this->logger->warning('Shopware connector config incomplete', [
                'indexer_uid' => (int)$configuration->getUid(),
                'base_url_configured' => $baseUrl !== '',
                'client_id_configured' => $clientId !== '',
                'client_secret_configured' => $clientSecret !== '',
            ]);

            return '';
        }

        /**
         * @var array<string, string> $payload
         */
        $payload = [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];

        $response = $this->requestFactory->request($baseUrl . '/api/oauth/token', 'POST', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
            'verify' => $configuration->isShopwareVerifyTls(),
        ]);

        $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            $this->logger->warning('Shopware OAuth response is not an array', [
                'indexer_uid' => (int)$configuration->getUid(),
            ]);

            return '';
        }

        $token = trim((string)($data['access_token'] ?? ''));
        if ($token === '') {
            $this->logger->warning('Shopware OAuth token missing', [
                'indexer_uid' => (int)$configuration->getUid(),
                'response' => $data,
            ]);
        }

        return $token;
    }


    /**
     * Fetches products updated since the given date.
     *
     * @param \DateTimeImmutable $since Updated lower bound.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @param int $page Page number.
     * @param int $limit Page size.
     * @param bool $includeTotalCount Include total count.
     * @param \DateTimeImmutable|null $before Optional upper bound.
     * @return array<string, mixed> Shopware API response.
     */
    public function fetchProducts(
        \DateTimeImmutable $since,
        IndexerConfig $configuration,
        int $page = 1,
        int $limit = 50,
        bool $includeTotalCount = true,
        ?\DateTimeImmutable $before = null
    ): array {
        $this->licenseService->requireValidLicense();

        $baseUrl = $this->resolveBaseUrl($configuration);
        if ($baseUrl === '') {
            return ['data' => [], 'total' => 0];
        }

        $token = $this->fetchAccessToken($configuration);
        if ($token === '') {
            return ['data' => [], 'total' => 0];
        }

        $filters = [
            [
                'type' => 'equals',
                'field' => 'parentId',
                'value' => null,
            ],
            [
                'type' => 'range',
                'field' => 'updatedAt',
                'parameters' => [
                    'gte' => $since->format('Y-m-d\\TH:i:s.000\\Z'),
                ],
            ],
        ];

        if ($before instanceof \DateTimeImmutable) {
            $filters[] = [
                'type' => 'range',
                'field' => 'updatedAt',
                'parameters' => [
                    'lt' => $before->format('Y-m-d\\TH:i:s.000\\Z'),
                ],
            ];
        }

        /**
         * @var array<string, mixed> $payload
         */
        $payload = [
            'includes' => [
                'category' => ['id', 'name'],
            ],
            'associations' => [
                'cover' => [
                    'associations' => [
                        'media' => [],
                    ],
                ],
                'categories' => [],
                'properties' => [
                    'associations' => [
                        'group' => [],
                    ],
                ],
                'manufacturer' => [],
            ],
            'filter' => $filters,
            'limit' => $limit,
            'page' => $page,
            'sort' => [
                ['field' => 'updatedAt', 'order' => 'ASC'],
                ['field' => 'id', 'order' => 'ASC'],
            ],
        ];

        if ($includeTotalCount) {
            $payload['total-count-mode'] = 1;
        }

        $response = $this->requestFactory->request($baseUrl . '/api/search/product', 'POST', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
            'verify' => $configuration->isShopwareVerifyTls(),
        ]);

        $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            return ['data' => [], 'total' => 0];
        }

        return $data;
    }


    /**
     * Fetches products by id for cleanup checks.
     *
     * @param array<int, string> $ids Product identifiers.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return array<string, array<string, mixed>> Products keyed by id.
     */
    public function fetchProductsByIds(array $ids, IndexerConfig $configuration): array
    {
        $this->licenseService->requireValidLicense();

        $ids = array_values(array_unique(array_filter(
            array_map(static fn (string $id): string => trim($id), $ids),
            static fn (string $id): bool => $id !== ''
        )));

        if ($ids === []) {
            return [];
        }

        $baseUrl = $this->resolveBaseUrl($configuration);
        if ($baseUrl === '') {
            return [];
        }

        $token = $this->fetchAccessToken($configuration);
        if ($token === '') {
            return [];
        }

        /**
         * @var array<string, mixed> $payload
         */
        $payload = [
            'filter' => [[
                'type' => 'equalsAny',
                'field' => 'id',
                'value' => implode('|', $ids),
            ]],
            'includes' => [
                'product' => ['id', 'active', 'updatedAt', 'createdAt'],
            ],
            'limit' => count($ids),
            'page' => 1,
        ];

        $response = $this->requestFactory->request($baseUrl . '/api/search/product', 'POST', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
            'verify' => $configuration->isShopwareVerifyTls(),
        ]);

        $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /**
         * @var array<string, array<string, mixed>> $products
         */
        $products = [];

        foreach ((array)($data['data'] ?? []) as $product) {
            if (!is_array($product)) {
                continue;
            }

            $id = trim((string)($product['id'] ?? ''));
            if ($id !== '') {
                $products[$id] = $product;
            }
        }

        return $products;
    }


    /**
     * Resolves the configured Shopware base URL.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return string Base URL without trailing slash.
     */
    private function resolveBaseUrl(IndexerConfig $configuration): string
    {
        return rtrim(trim($configuration->getShopwareBaseUrl()), '/');
    }
}
