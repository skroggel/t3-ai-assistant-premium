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


namespace Madj2k\AiAssistantPremium\Indexing\Indexer;

use Madj2k\AiCore\Indexing\VectorDocumentIndexer;
use Madj2k\AiCore\Connection\Resolver\VectorStoreConnectorResolver;
use Madj2k\AiCore\Connection\VectorStore\DTO\VectorCollection;
use Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig;
use Madj2k\AiAssistant\Indexing\Domain\Repository\IndexerConfigRepository;
use Madj2k\AiAssistant\Indexing\Domain\Repository\IndexerSourceRepository;
use Madj2k\AiCore\Indexing\DTO\IndexableDocument;
use Madj2k\AiCore\DTO\DocumentMetadata;
use Madj2k\AiCore\Indexing\DTO\IndexingRequest;
use Madj2k\AiCore\Indexing\DTO\IndexingResult;
use Madj2k\AiAssistant\Indexing\Indexer\AbstractIndexer;
use Madj2k\AiCore\Indexing\Indexer\IndexerInterface;
use Madj2k\AiAssistant\Indexing\Service\SourceStateService;
use Madj2k\AiCore\Indexing\Resolver\IndexingConnectorResolver;
use Madj2k\AiAssistantPremium\Indexing\Connector\ShopwareConnectorInterface;
use Madj2k\AiAssistantPremium\Indexing\Domain\Model\ShopwareState;
use Madj2k\AiAssistantPremium\Indexing\Domain\Repository\ShopwareStateRepository;
use Madj2k\AiAssistantPremium\License\LicenseService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class ShopwareIndexer
 *
 * Indexes Shopware products in cursor-based batches.
 *
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
final class ShopwareIndexer extends AbstractIndexer implements IndexerInterface
{
    /**
     * Logger.
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected LoggerInterface $logger;


    /**
     * Constructor.
     *
     * @inheritDoc
     * @param \Madj2k\AiCore\Indexing\Resolver\IndexingConnectorResolver $connectorRegistry Connector registry.
     * @param \Madj2k\AiAssistantPremium\Indexing\Domain\Repository\ShopwareStateRepository $shopwareStateRepository Shopware state repository.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Repository\IndexerSourceRepository $indexerSourceRepository Index source repository.
     * @param \Psr\Log\LoggerInterface|null $logger Logger.
     */
    public function __construct(
        IndexerConfigRepository                  $indexerConfigRepository,
        SourceStateService                       $sourceStateService,
        VectorDocumentIndexer                    $vectorDocumentIndexer,
        private readonly VectorStoreConnectorResolver $vectorStoreConnectorResolver,
        private readonly IndexingConnectorResolver $connectorRegistry,
        private readonly ShopwareStateRepository $shopwareStateRepository,
        private readonly IndexerSourceRepository $indexerSourceRepository,
        private readonly LicenseService $licenseService,
        ?LoggerInterface                         $logger = null
    ) {
        parent::__construct(
            $indexerConfigRepository,
            $sourceStateService,
            $vectorDocumentIndexer,
        );

        $this->logger = $logger ?? GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }


    /**
     * @inheritDoc
     */
    public function getIdentifier(): string
    {
        return 'aiassistant.indexer.shopware';
    }


    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'Shopware product indexer';
    }


    /**
     * @inheritDoc
     */
    public function getSourceType(): string
    {
        return 'external';
    }


    /**
     * @inheritDoc
     */
    public function index(IndexingRequest $request): IndexingResult
    {
        $this->licenseService->requireValidLicense();

        $result = new IndexingResult();

        foreach ($this->resolveShopwareConfigurations($request->getIndexerUid()) as $configuration) {
            if ($request->getOption('mode') === 'cleanup_deleted') {
                $this->cleanupDeletedConfiguration($configuration, $request, $result);
                continue;
            }

            $this->indexConfiguration($configuration, $request, $result);
        }

        return $result;
    }


    /**
     * Indexes one Shopware indexer configuration.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @param \Madj2k\AiCore\Indexing\DTO\IndexingRequest $request Indexing request.
     * @param \Madj2k\AiCore\Indexing\DTO\IndexingResult $result Indexing result.
     * @return void
     */
    private function indexConfiguration(IndexerConfig $configuration, IndexingRequest $request, IndexingResult $result): void
    {
        $collection = $this->resolveCollection($configuration, $request->getCollection());
        if ($collection === '') {
            $result->increaseSkipped();
            $result->addDetail('shopware_configuration_' . (int)$configuration->getUid(), 'Skipped because no collection is configured.');
            return;
        }

        if (!$this->hasRequiredCredentials($configuration)) {
            $result->increaseSkipped();
            $result->addDetail('shopware_configuration_' . (int)$configuration->getUid(), 'Skipped because Shopware credentials are incomplete.');
            return;
        }

        $state = $this->resolveState($configuration);
        $startedAt = time();
        $upperBound = (new \DateTimeImmutable('@' . $startedAt))->setTimezone(new \DateTimeZone('UTC'));

        /** @var array{0:int,1:string} $cursor */
        $cursor = $this->resolveCursor($request, $state);
        $cursorUpdatedAt = $cursor[0];
        $cursorSourceId = $cursor[1];
        $since = $this->resolveSince($configuration, $cursorUpdatedAt, $request->getSince());

        if (!$request->isDryRun()) {
            $state->setLastRunStartedAt($startedAt);
            $state->setStatus('running');
            $state->setLastError('');
            $this->shopwareStateRepository->save($state);
        }

        try {
            $page = 1;
            $limit = $request->getLimit() ?? 100;

            while (!$this->isLimitReached($request, $result)) {
                $response = $this->getShopwareConnector()->fetchProducts(
                    $since,
                    $configuration,
                    $page,
                    max(1, min(500, $limit + 1)),
                    false,
                    $upperBound
                );

                /** @var array<int, mixed> $products */
                $products = is_array($response['data'] ?? null) ? $response['data'] : [];
                if ($products === []) {
                    break;
                }

                foreach ($products as $product) {
                    if (!is_array($product)) {
                        continue;
                    }

                    $sourceIdentifier = trim((string)($product['id'] ?? ''));
                    $updatedAt = $this->extractProductTimestamp($product);
                    if ($sourceIdentifier === '' || $updatedAt <= 0) {
                        $result->increaseFailed();
                        continue;
                    }

                    if (!$this->isAfterCursor($updatedAt, $sourceIdentifier, $cursorUpdatedAt, $cursorSourceId)) {
                        continue;
                    }

                    if ($this->isLimitReached($request, $result)) {
                        $result->setHasMore(true);
                        $result->setNextCursor($this->encodeCursor($updatedAt, $sourceIdentifier));
                        break 2;
                    }

                    $result->increaseProcessed();

                    if (!$this->isProductActive($product)) {
                        $this->removeIndexedProduct($configuration, $collection, $sourceIdentifier, $request, $result);
                        $result->increaseSkipped();
                    } else {
                        $document = $this->createDocument($product, $configuration, $collection);
                        if (!$document instanceof IndexableDocument) {
                            $result->increaseFailed();
                        } else {
                            $this->indexDocument($configuration, $document, $request, $result);
                        }
                    }

                    $cursorUpdatedAt = $updatedAt;
                    $cursorSourceId = $sourceIdentifier;
                    $result->setNextCursor($this->encodeCursor($cursorUpdatedAt, $cursorSourceId));

                    if (!$request->isDryRun()) {
                        $this->updateRunningState($state, $cursorUpdatedAt, $cursorSourceId, $startedAt, $result);
                    }
                }

                $page++;
            }

            if (!$request->isDryRun()) {
                $this->finishState($state, $cursorUpdatedAt, $cursorSourceId, $startedAt, $result, 'ok', '');
            }
        } catch (\Throwable $exception) {
            if (!$request->isDryRun()) {
                $this->finishState($state, $cursorUpdatedAt, $cursorSourceId, $startedAt, $result, 'error', $exception->getMessage());
            }

            $this->logger->error('Shopware indexing failed', [
                'indexer_uid' => (int)$configuration->getUid(),
                'cursor_updated_at' => $cursorUpdatedAt,
                'cursor_source_id' => $cursorSourceId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }



    /**
     * Cleans up locally indexed Shopware products that no longer exist remotely.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @param \Madj2k\AiCore\Indexing\DTO\IndexingRequest $request Indexing request.
     * @param \Madj2k\AiCore\Indexing\DTO\IndexingResult $result Indexing result.
     * @return void
     */
    private function cleanupDeletedConfiguration(IndexerConfig $configuration, IndexingRequest $request, IndexingResult $result): void
    {
        $collection = $this->resolveCollection($configuration, $request->getCollection());
        if ($collection === '' || !$this->hasRequiredCredentials($configuration)) {
            $result->increaseSkipped();
            return;
        }

        $state = $this->resolveState($configuration);
        $cursor = $request->isResetCursor() ? '' : ($request->getCursor() !== '' ? $request->getCursor() : $state->getCleanupCursorSourceId());
        $limit = $request->getLimit() ?? 100;
        $vectorStoreConnectionUid = $this->sourceStateService->resolveVectorStoreConnectionUid($configuration);
        $sources = $this->indexerSourceRepository->findByStorageCollectionAfterSourceId('shopware_product', $vectorStoreConnectionUid, $collection, $cursor, $limit);

        if ($sources === [] && $cursor !== '') {
            $cursor = '';
            $sources = $this->indexerSourceRepository->findByStorageCollectionAfterSourceId('shopware_product', $vectorStoreConnectionUid, $collection, $cursor, $limit);
            $result->addDetail('cleanup_cursor', 'wrapped');
        }

        $batchSize = max(1, min(500, (int)$request->getOption('batch_size', 100)));
        $lastProcessedSourceIdentifier = '';

        foreach (array_chunk($sources, $batchSize) as $sourceBatch) {
            /** @var array<int, string> $sourceIdentifiers */
            $sourceIdentifiers = [];
            foreach ($sourceBatch as $source) {
                $sourceIdentifiers[] = $source->getSourceId();
            }

            $productsByIdentifier = $this->getShopwareConnector()->fetchProductsByIds(
                $sourceIdentifiers,
                $configuration
            );

            foreach ($sourceBatch as $source) {
                $sourceIdentifier = $source->getSourceId();
                $lastProcessedSourceIdentifier = $sourceIdentifier;
                $result->increaseProcessed();

                if (isset($productsByIdentifier[$sourceIdentifier])) {
                    $result->increaseSkipped();
                    continue;
                }

                foreach ($this->getStorageSourceHashesForStoredSource($configuration, $source) as $storageSourceHash) {
                    $this->deleteStoredSourceHash($configuration, $collection, $storageSourceHash, $request->isDryRun());
                }

                $result->increaseRemoved();
                if (!$request->isDryRun()) {
                    $source->setStatus('removed');
                    $source->setLastError('');
                    $this->indexerSourceRepository->save($source);
                }
            }
        }

        $hasMore = count($sources) >= $limit;
        $result->setHasMore($hasMore);
        $result->setNextCursor($hasMore ? $lastProcessedSourceIdentifier : '');

        if (!$request->isDryRun()) {
            $state->setCleanupCursorSourceId($hasMore ? $lastProcessedSourceIdentifier : '');
            $state->setCleanupLastRunAt(time());
            $this->shopwareStateRepository->save($state);
        }
    }




    /**
     * Deletes a stored source hash from the configured vector store.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @param string $collection Collection.
     * @param string $sourceHash Source hash.
     * @param bool $dryRun Dry-run flag.
     * @return void
     */
    private function deleteStoredSourceHash(
        IndexerConfig $configuration,
        string $collection,
        string $sourceHash,
        bool $dryRun
    ): void {
        if ($dryRun || trim($collection) === '' || trim($sourceHash) === '') {
            return;
        }

        $vectorStoreConnection = $configuration->getVectorStoreConnection();
        if ($vectorStoreConnection === null) {
            throw new \RuntimeException('No vector store connection configured for Shopware indexer.', 1780580201);
        }

        $this->vectorStoreConnectorResolver
            ->get($vectorStoreConnection->getConnectorIdentifier())
            ->deleteBySourceHash($vectorStoreConnection, new VectorCollection($collection), $sourceHash);
    }



    /**
     * Returns all vector storage source hashes for a persisted source state.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerSource $source Source state.
     * @return array<int, string> Storage source hashes.
     */
    private function getStorageSourceHashesForStoredSource(IndexerConfig $configuration, \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerSource $source): array
    {
        $metadata = new DocumentMetadata($source->getSourceType(), $source->getSourceId());
        $metadata->setLanguage($source->getLanguage());
        $metadata->setLanguageId($source->getLanguageId());
        $document = new IndexableDocument('', $metadata);

        $hashes = [$this->sourceStateService->createSourceHash($document)];
        if ($source->getStorageSourceHash() !== '') {
            $hashes[] = $source->getStorageSourceHash();
        }

        return array_values(array_unique(array_filter(array_map('trim', $hashes))));
    }

    /**
     * Resolves the Shopware configurations.
     *
     * @param int|null $indexerUid Indexer uid.
     * @return array<int, \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig> Configurations.
     */
    private function resolveShopwareConfigurations(?int $indexerUid): array
    {
        if (($indexerUid ?? 0) > 0) {
            $configuration = $this->indexerConfigRepository->findByUid((int)$indexerUid);
            if (!$configuration instanceof IndexerConfig) {
                return [];
            }

            return $this->isShopwareConfiguration($configuration) ? [$configuration] : [];
        }

        $configurations = $this->indexerConfigRepository->findByIndexerIdentifier($this->getIdentifier());
        if ($configurations !== []) {
            return $configurations;
        }

        return array_values(array_filter(
            $this->indexerConfigRepository->findByType($this->getSourceType()),
            fn (IndexerConfig $configuration): bool => $this->isShopwareConfiguration($configuration)
        ));
    }


    /**
     * Returns whether the given configuration belongs to this indexer.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return bool Shopware configuration flag.
     */
    private function isShopwareConfiguration(IndexerConfig $configuration): bool
    {
        if ($configuration->getIndexerIdentifier() !== '') {
            return $configuration->getIndexerIdentifier() === $this->getIdentifier();
        }

        return $configuration->getType() === $this->getSourceType()
            && ($configuration->getShopwareBaseUrl() !== '' || $configuration->getShopwareApiKey() !== '');
    }


    /**
     * Returns whether all required credentials are available.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return bool Credentials flag.
     */
    private function hasRequiredCredentials(IndexerConfig $configuration): bool
    {
        return $configuration->getShopwareBaseUrl() !== ''
            && $configuration->getShopwareClientId() !== ''
            && $configuration->getShopwareApiKey() !== '';
    }


    /**
     * Resolves or creates the Shopware state.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return \Madj2k\AiAssistantPremium\Indexing\Domain\Model\ShopwareState State.
     */
    private function resolveState(IndexerConfig $configuration): ShopwareState
    {
        $state = $this->shopwareStateRepository->findByConnectorAndIndexer(0, (int)$configuration->getUid());
        if ($state instanceof ShopwareState) {
            return $state;
        }

        $state = new ShopwareState();
        $state->setPid(0);
        $state->setConnectorUid(0);
        $state->setIndexerUid((int)$configuration->getUid());

        return $state;
    }


    /**
     * Resolves the active cursor.
     *
     * @param \Madj2k\AiCore\Indexing\DTO\IndexingRequest $request Indexing request.
     * @param \Madj2k\AiAssistantPremium\Indexing\Domain\Model\ShopwareState $state State.
     * @return array{0:int,1:string} Cursor.
     */
    private function resolveCursor(IndexingRequest $request, ShopwareState $state): array
    {
        if ($request->isResetCursor()) {
            return [0, ''];
        }

        if ($request->getCursor() !== '') {
            return $this->decodeCursor($request->getCursor());
        }

        return [$state->getCursorUpdatedAt(), $state->getCursorSourceId()];
    }


    /**
     * Resolves the lower timestamp bound.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @param int $cursorUpdatedAt Cursor timestamp.
     * @param \DateTimeImmutable|null $sinceOverride Since override.
     * @return \DateTimeImmutable Since date.
     */
    private function resolveSince(IndexerConfig $configuration, int $cursorUpdatedAt, ?\DateTimeImmutable $sinceOverride = null): \DateTimeImmutable
    {
        if ($sinceOverride instanceof \DateTimeImmutable) {
            return $sinceOverride->setTimezone(new \DateTimeZone('UTC'));
        }

        if ($cursorUpdatedAt > 0) {
            return (new \DateTimeImmutable('@' . $cursorUpdatedAt))->setTimezone(new \DateTimeZone('UTC'));
        }

        $lookbackDays = $configuration->getShopwareLookbackDays() > 0
            ? $configuration->getShopwareLookbackDays()
            : 1;

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . $lookbackDays . ' days');
    }


    /**
     * Extracts the product timestamp.
     *
     * @param array<string, mixed> $product Product data.
     * @return int Timestamp.
     */
    private function extractProductTimestamp(array $product): int
    {
        $updatedAt = (string)($product['updatedAt'] ?? $product['createdAt'] ?? '');
        if ($updatedAt === '') {
            return 0;
        }

        try {
            return (new \DateTimeImmutable($updatedAt))->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }


    /**
     * Checks whether a product is after the current cursor.
     *
     * @param int $updatedAt Product update timestamp.
     * @param string $sourceIdentifier Product source identifier.
     * @param int $cursorUpdatedAt Cursor timestamp.
     * @param string $cursorSourceId Cursor source identifier.
     * @return bool After cursor flag.
     */
    private function isAfterCursor(int $updatedAt, string $sourceIdentifier, int $cursorUpdatedAt, string $cursorSourceId): bool
    {
        if ($updatedAt > $cursorUpdatedAt) {
            return true;
        }

        if ($updatedAt < $cursorUpdatedAt) {
            return false;
        }

        return $cursorSourceId === '' || strcmp($sourceIdentifier, $cursorSourceId) > 0;
    }


    /**
     * Encodes the cursor.
     *
     * @param int $updatedAt Updated timestamp.
     * @param string $sourceIdentifier Source identifier.
     * @return string Cursor.
     */
    private function encodeCursor(int $updatedAt, string $sourceIdentifier): string
    {
        return $updatedAt . '|' . $sourceIdentifier;
    }


    /**
     * Decodes the cursor.
     *
     * @param string $cursor Cursor.
     * @return array{0:int,1:string} Decoded cursor.
     */
    private function decodeCursor(string $cursor): array
    {
        if (str_contains($cursor, '|')) {
            [$updatedAt, $sourceIdentifier] = explode('|', $cursor, 2);

            return [(int)$updatedAt, trim($sourceIdentifier)];
        }

        return [(int)$cursor, ''];
    }


    /**
     * Returns whether a product is active.
     *
     * @param array<string, mixed> $product Product data.
     * @return bool Active flag.
     */
    private function isProductActive(array $product): bool
    {
        return (bool)($product['active'] ?? true);
    }


    /**
     * Removes an indexed product from storage when it became inactive.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @param string $collection Collection.
     * @param string $sourceIdentifier Source identifier.
     * @param \Madj2k\AiCore\Indexing\DTO\IndexingRequest $request Indexing request.
     * @param \Madj2k\AiCore\Indexing\DTO\IndexingResult $result Indexing result.
     * @return void
     */
    private function removeIndexedProduct(
        IndexerConfig $configuration,
        string $collection,
        string $sourceIdentifier,
        IndexingRequest $request,
        IndexingResult $result
    ): void {
        $metadata = new DocumentMetadata('shopware_product', $sourceIdentifier);
        $document = new IndexableDocument('', $metadata);
        $storageSourceHashes = $this->sourceStateService->getStorageSourceHashesForDeletion($configuration, $document, $collection);

        if ($storageSourceHashes !== []) {
            foreach ($storageSourceHashes as $storageSourceHash) {
                $this->deleteStoredSourceHash($configuration, $collection, $storageSourceHash, $request->isDryRun());
            }
            $result->increaseRemoved();

            if (!$request->isDryRun()) {
                $this->sourceStateService->markRemoved($configuration, $document, $collection);
            }
        }
    }



    /**
     * Updates the running state.
     *
     * @param \Madj2k\AiAssistantPremium\Indexing\Domain\Model\ShopwareState $state State.
     * @param int $cursorUpdatedAt Cursor timestamp.
     * @param string $cursorSourceId Cursor source id.
     * @param int $startedAt Start timestamp.
     * @param \Madj2k\AiCore\Indexing\DTO\IndexingResult $result Result.
     * @return void
     */
    private function updateRunningState(
        ShopwareState $state,
        int $cursorUpdatedAt,
        string $cursorSourceId,
        int $startedAt,
        IndexingResult $result
    ): void {
        $state->setCursorUpdatedAt($cursorUpdatedAt);
        $state->setCursorSourceId($cursorSourceId);
        $state->setLastRunStartedAt($startedAt);
        $state->setStatus('running');
        $state->setLastError('');
        $state->setItemsProcessed($result->getProcessed());
        $state->setItemsIndexed($result->getIndexed());
        $state->setItemsSkipped($result->getSkipped());
        $state->setItemsFailed($result->getFailed());

        $this->shopwareStateRepository->save($state);
    }


    /**
     * Finishes the state.
     *
     * @param \Madj2k\AiAssistantPremium\Indexing\Domain\Model\ShopwareState $state State.
     * @param int $cursorUpdatedAt Cursor timestamp.
     * @param string $cursorSourceId Cursor source id.
     * @param int $startedAt Start timestamp.
     * @param \Madj2k\AiCore\Indexing\DTO\IndexingResult $result Result.
     * @param string $status Status.
     * @param string $lastError Last error.
     * @return void
     */
    private function finishState(
        ShopwareState $state,
        int $cursorUpdatedAt,
        string $cursorSourceId,
        int $startedAt,
        IndexingResult $result,
        string $status,
        string $lastError
    ): void {
        $state->setCursorUpdatedAt($cursorUpdatedAt);
        $state->setCursorSourceId($cursorSourceId);
        $state->setLastRunStartedAt($startedAt);
        $state->setLastRunFinishedAt(time());
        $state->setStatus($status);
        $state->setLastError($lastError);
        $state->setItemsProcessed($result->getProcessed());
        $state->setItemsIndexed($result->getIndexed());
        $state->setItemsSkipped($result->getSkipped());
        $state->setItemsFailed($result->getFailed());

        $this->shopwareStateRepository->save($state);
    }

    /**
     * Returns the Shopware connector.
     *
     * @return \Madj2k\AiAssistantPremium\Indexing\Connector\ShopwareConnectorInterface Shopware connector.
     */
    private function getShopwareConnector(): ShopwareConnectorInterface
    {
        $connector = $this->connectorRegistry->get('aiassistant.connector.shopware');
        if (!$connector instanceof ShopwareConnectorInterface) {
            throw new \UnexpectedValueException('Registered Shopware connector does not implement ShopwareConnectorInterface.', 1760001102);
        }

        return $connector;
    }



    /**
     * Creates an indexable document from one Shopware product.
     *
     * @param array<string, mixed> $product Product payload.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @param string $collection Target collection.
     * @return \Madj2k\AiCore\Indexing\DTO\IndexableDocument|null Document.
     */
    private function createDocument(array $product, IndexerConfig $configuration, string $collection): ?IndexableDocument
    {
        $sourceIdentifier = trim((string)($product['id'] ?? ''));
        $changedAt = $this->extractProductTimestamp($product);
        if ($sourceIdentifier === '' || $changedAt <= 0) {
            return null;
        }

        $content = $this->buildContent($product, $configuration);
        if ($content === '') {
            return null;
        }

        $metadata = $this->buildMetadata($product, $configuration, $sourceIdentifier, $changedAt);

        return new IndexableDocument($content, $metadata);
    }


    /**
     * Builds the indexable text content.
     *
     * @param array<string, mixed> $product Product payload.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return string Text content.
     */
    private function buildContent(array $product, IndexerConfig $configuration): string
    {
        /** @var array<int, string> $parts */
        $parts = [];

        $title = $this->extractTranslatedValue($product, 'name');
        if ($title !== '') {
            $parts[] = $title;
        }

        $description = $this->extractTranslatedValue($product, 'description');
        if ($description !== '') {
            $parts[] = trim(strip_tags($description));
        }

        $productNumber = trim((string)($product['productNumber'] ?? ''));
        if ($productNumber !== '') {
            $parts[] = 'Product number: ' . $productNumber;
        }

        $manufacturer = $this->extractNestedTranslatedValue($product, ['manufacturer'], 'name');
        if ($manufacturer !== '') {
            $parts[] = 'Manufacturer: ' . $manufacturer;
        }

        $customFieldText = $this->formatCustomFields((array)($product['customFields'] ?? []), $configuration);
        if ($customFieldText !== '') {
            $parts[] = $customFieldText;
        }

        $indexedFieldText = $this->formatIndexedFields($product, $configuration);
        if ($indexedFieldText !== '') {
            $parts[] = $indexedFieldText;
        }

        return trim(implode("\n\n", $parts));
    }


    /**
     * Builds structured document metadata.
     *
     * @param array<string, mixed> $product Product payload.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @param string $sourceIdentifier Source identifier.
     * @param int $changedAt Changed timestamp.
     * @return \Madj2k\AiCore\DTO\DocumentMetadata Metadata.
     */
    private function buildMetadata(
        array $product,
        IndexerConfig $configuration,
        string $sourceIdentifier,
        int $changedAt
    ): DocumentMetadata {
        $title = $this->extractTranslatedValue($product, 'name');
        $categories = $this->extractCategoryNames($product);
        $customFields = $this->extractCustomFields((array)($product['customFields'] ?? []), $configuration);
        $structuredMetadata = $this->buildStructuredMetadata($customFields, $categories);

        $metadata = new DocumentMetadata('shopware_product', $sourceIdentifier);
        $metadata->setTitle($title);
        $metadata->setUrl($this->buildProductUrl($sourceIdentifier, $configuration));
        $metadata->setChangedAt($changedAt);
        $metadata->addAdditional('external_indexer_uid', (int)$configuration->getUid());
        $metadata->addAdditional('shopware_id', $sourceIdentifier);
        $metadata->addAdditional('customFields', $customFields);
        $metadata->addAdditional('title', $title);
        $metadata->addAdditional('product_title', $title);
        $metadata->addAdditional('productNumber', (string)($product['productNumber'] ?? ''));
        $metadata->addAdditional('category', $categories);
        $metadata->addAdditional('keywords', $categories);

        foreach ($structuredMetadata as $key => $value) {
            $metadata->addAdditional($key, $value);
        }

        foreach ($configuration->getAdditionalMetadataArray() as $key => $value) {
            if (is_string($key) && trim($key) !== '') {
                $metadata->addAdditional($key, $value);
            }
        }

        return $metadata;
    }


    /**
     * Extracts a translated value from the product root.
     *
     * @param array<string, mixed> $product Product payload.
     * @param string $field Field name.
     * @return string Value.
     */
    private function extractTranslatedValue(array $product, string $field): string
    {
        return trim((string)($product['translated'][$field] ?? $product[$field] ?? ''));
    }


    /**
     * Extracts a translated value from a nested object.
     *
     * @param array<string, mixed> $product Product payload.
     * @param array<int, string> $path Path.
     * @param string $field Field name.
     * @return string Value.
     */
    private function extractNestedTranslatedValue(array $product, array $path, string $field): string
    {
        /** @var mixed $node */
        $node = $product;
        foreach ($path as $segment) {
            if (!is_array($node) || !isset($node[$segment])) {
                return '';
            }

            $node = $node[$segment];
        }

        if (!is_array($node)) {
            return '';
        }

        return trim((string)($node['translated'][$field] ?? $node[$field] ?? ''));
    }


    /**
     * Formats selected custom fields for the indexable text.
     *
     * @param array<string, mixed> $customFields Custom fields.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return string Formatted custom fields.
     */
    private function formatCustomFields(array $customFields, IndexerConfig $configuration): string
    {
        $filteredCustomFields = $this->extractCustomFields($customFields, $configuration);
        if ($filteredCustomFields === []) {
            return '';
        }

        /** @var array<int, string> $lines */
        $lines = [];
        foreach ($filteredCustomFields as $key => $value) {
            if (is_scalar($value)) {
                $lines[] = $key . ': ' . (string)$value;
            }
        }

        return $lines !== [] ? "Custom fields:\n" . implode("\n", $lines) : '';
    }


    /**
     * Extracts configured custom fields.
     *
     * @param array<string, mixed> $customFields Custom fields.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return array<string, mixed> Custom fields.
     */
    private function extractCustomFields(array $customFields, IndexerConfig $configuration): array
    {
        $allowedFields = $this->splitList($configuration->getShopwareCustomFields());
        if ($allowedFields === []) {
            return $customFields;
        }

        /** @var array<string, mixed> $filtered */
        $filtered = [];
        foreach ($customFields as $key => $value) {
            if (in_array((string)$key, $allowedFields, true)) {
                $filtered[(string)$key] = $value;
            }
        }

        return $filtered;
    }


    /**
     * Formats configured indexed fields for the indexable text.
     *
     * @param array<string, mixed> $product Product payload.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return string Formatted indexed fields.
     */
    private function formatIndexedFields(array $product, IndexerConfig $configuration): string
    {
        $paths = $this->splitList($configuration->getShopwareIndexedFields());
        if ($paths === []) {
            return '';
        }

        /** @var array<int, string> $lines */
        $lines = [];
        foreach ($paths as $path) {
            /** @var array<int, string> $normalizedValues */
            $normalizedValues = [];
            foreach ($this->extractValuesByPath($product, $path) as $value) {
                $normalizedValue = $this->normalizeIndexedValue($value);
                if ($normalizedValue !== '') {
                    $normalizedValues[] = $normalizedValue;
                }
            }

            $normalizedValues = array_values(array_unique($normalizedValues));
            if ($normalizedValues !== []) {
                $lines[] = $path . ': ' . implode(', ', $normalizedValues);
            }
        }

        return $lines !== [] ? "Additional fields:\n" . implode("\n", $lines) : '';
    }


    /**
     * Extracts category names.
     *
     * @param array<string, mixed> $product Product payload.
     * @return array<int, string> Category names.
     */
    private function extractCategoryNames(array $product): array
    {
        /** @var array<int, string> $names */
        $names = [];

        foreach ((array)($product['categories'] ?? []) as $category) {
            if (!is_array($category)) {
                continue;
            }

            $name = trim((string)($category['translated']['name'] ?? $category['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }


    /**
     * Builds standardized metadata from known custom field names.
     *
     * @param array<string, mixed> $customFields Custom fields.
     * @param array<int, string> $categories Categories.
     * @return array<string, mixed> Structured metadata.
     */
    private function buildStructuredMetadata(array $customFields, array $categories): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = [];

        foreach ($customFields as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $normalizedKey = strtolower((string)$key);
            $normalizedValue = trim((string)$value);
            if ($normalizedValue === '') {
                continue;
            }

            if (!isset($metadata['contact_name']) && preg_match('/contact|ansprechpartner|ansprechperson|kontakt|name/', $normalizedKey) === 1) {
                $metadata['contact_name'] = $normalizedValue;
                continue;
            }

            if (!isset($metadata['phone']) && preg_match('/phone|telefon|telephone|tel/', $normalizedKey) === 1) {
                $metadata['phone'] = $normalizedValue;
                continue;
            }

            if (!isset($metadata['email']) && preg_match('/email|e_mail|mail/', $normalizedKey) === 1) {
                $metadata['email'] = $normalizedValue;
                continue;
            }

            if (!isset($metadata['department']) && preg_match('/department|bereich|abteilung|zustaendig|zustaendigkeit|zuständig/', $normalizedKey) === 1) {
                $metadata['department'] = $normalizedValue;
            }
        }

        if (!isset($metadata['department']) && $categories !== []) {
            $metadata['department'] = $categories[0];
        }

        if (isset($metadata['contact_name']) || isset($metadata['phone']) || isset($metadata['email'])) {
            $metadata['contact_sensitive'] = true;
        }

        return $metadata;
    }


    /**
     * Extracts values by dot path from nested product data.
     *
     * @param array<string, mixed> $product Product payload.
     * @param string $path Dot path.
     * @return array<int, mixed> Values.
     */
    private function extractValuesByPath(array $product, string $path): array
    {
        $segments = array_values(array_filter(
            explode('.', $path),
            static fn (string $segment): bool => trim($segment) !== ''
        ));

        if ($segments === []) {
            return [];
        }

        /** @var array<int, mixed> $nodes */
        $nodes = [$product];

        foreach ($segments as $segment) {
            $isArraySegment = str_ends_with($segment, '[]');
            $key = $isArraySegment ? substr($segment, 0, -2) : $segment;

            /** @var array<int, mixed> $nextNodes */
            $nextNodes = [];
            foreach ($nodes as $node) {
                if (!is_array($node) || !array_key_exists($key, $node)) {
                    continue;
                }

                /** @var mixed $value */
                $value = $node[$key];
                if ($isArraySegment && is_array($value)) {
                    foreach ($value as $item) {
                        $nextNodes[] = $item;
                    }
                } else {
                    $nextNodes[] = $value;
                }
            }

            $nodes = $nextNodes;
            if ($nodes === []) {
                break;
            }
        }

        return $nodes;
    }


    /**
     * Normalizes one indexed value.
     *
     * @param mixed $value Value.
     * @return string Normalized value.
     */
    private function normalizeIndexedValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string)$value);
        }

        return '';
    }


    /**
     * Builds a product URL from the configuration.
     *
     * @param string $sourceIdentifier Source identifier.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return string Product URL.
     */
    private function buildProductUrl(string $sourceIdentifier, IndexerConfig $configuration): string
    {
        $baseUrl = rtrim($configuration->getShopwareDownloadBaseUrl(), '/');
        $downloadPath = trim($configuration->getShopwareDownloadPath(), '/');

        if ($baseUrl === '') {
            return '';
        }

        return $baseUrl . ($downloadPath !== '' ? '/' . $downloadPath : '') . '/' . $sourceIdentifier;
    }


    /**
     * Splits comma or newline separated values.
     *
     * @param string $value Raw value.
     * @return array<int, string> Items.
     */
    private function splitList(string $value): array
    {
        $parts = preg_split('/[\r\n,]+/', $value) ?: [];
        $items = array_map(static fn (string $item): string => trim($item), $parts);
        $items = array_filter($items, static fn (string $item): bool => $item !== '');

        return array_values(array_unique($items));
    }
}
