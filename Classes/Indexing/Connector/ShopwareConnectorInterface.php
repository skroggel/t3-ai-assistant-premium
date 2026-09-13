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

use Madj2k\AiCore\Indexing\Connector\ConnectorInterface;
use Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig;

/**
 * Interface ShopwareConnectorInterface
 *
 * Defines the contract for Shopware product source connectors.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
interface ShopwareConnectorInterface extends ConnectorInterface
{
    /**
     * Fetches an OAuth access token.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return string Access token.
     */
    public function fetchAccessToken(IndexerConfig $configuration): string;


    /**
     * Fetches Shopware products updated since the given date.
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
    ): array;


    /**
     * Fetches Shopware products by their identifiers.
     *
     * @param array<int, string> $ids Product identifiers.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return array<string, array<string, mixed>> Products indexed by identifier.
     */
    public function fetchProductsByIds(array $ids, IndexerConfig $configuration): array;
}
