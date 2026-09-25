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


namespace Madj2k\AiAssistantPremium\Indexing\Service;

use Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig;
use Madj2k\AiCore\Indexing\DTO\IndexableDocument;
use Madj2k\AiCore\DTO\DocumentMetadata;

/**
 * Class ShopwareProductDocumentFactory
 *
 * Converts Shopware product API payloads into standardized indexable documents.
 *
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final class ShopwareProductDocumentFactory
{
    /**
     * Builds the client configuration array expected by the Shopware client.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return array<string, mixed> Client configuration.
     */
    public function buildClientConfiguration(IndexerConfig $configuration): array
    {
        return [
            'base_url' => $configuration->getShopwareBaseUrl(),
            'download_base_url' => $configuration->getShopwareDownloadBaseUrl(),
            'download_path' => $configuration->getShopwareDownloadPath(),
            'client_id' => $configuration->getShopwareClientId(),
            'client_secret' => $configuration->getShopwareApiKey(),
            'verify_tls' => $configuration->isShopwareVerifyTls(),
            'lookback_days' => $configuration->getShopwareLookbackDays(),
            'custom_fields' => $configuration->getShopwareCustomFields(),
            'indexed_fields' => $configuration->getShopwareIndexedFields(),
        ];
    }


    /**
     * Creates an indexable document from one Shopware product.
     *
     * @param array<string, mixed> $product Product payload.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @param string $collection Target collection.
     * @return \Madj2k\AiCore\Indexing\DTO\IndexableDocument|null Document.
     */
    public function createDocument(array $product, IndexerConfig $configuration, string $collection): ?IndexableDocument
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
     * Extracts a timestamp from Shopware product data.
     *
     * @param array<string, mixed> $product Product payload.
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
