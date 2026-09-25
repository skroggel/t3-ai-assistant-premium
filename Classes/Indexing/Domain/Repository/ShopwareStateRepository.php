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


namespace Madj2k\AiAssistantPremium\Indexing\Domain\Repository;

use Madj2k\AiAssistantPremium\Indexing\Domain\Model\ShopwareState;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Class ShopwareStateRepository
 *
 * Repository for Shopware indexing state records.
 *
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
class ShopwareStateRepository extends Repository
{
    /**
     * Disables storage PID restriction for root-level runtime records.
     *
     * @return void
     */
    public function initializeObject(): void
    {
        /** @var Typo3QuerySettings $querySettings */
        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }


    /**
     * Finds a state by connector and indexer.
     *
     * @param int $connectorUid Connector uid.
     * @param int $indexerUid Indexer uid.
     * @return ShopwareState|null State record.
     */
    public function findByConnectorAndIndexer(int $connectorUid, int $indexerUid): ?ShopwareState
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('connectorUid', $connectorUid),
                $query->equals('indexerUid', $indexerUid)
            )
        );
        $query->setLimit(1);

        /** @var ShopwareState|null $state */
        $state = $query->execute()->getFirst();

        return $state instanceof ShopwareState ? $state : null;
    }


    /**
     * Saves a Shopware state record.
     *
     * @param \Madj2k\AiAssistantPremium\Indexing\Domain\Model\ShopwareState $state State.
     * @return void
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException
     */
    public function save(ShopwareState $state): void
    {
        if ($state->getUid() === null) {
            parent::add($state);
        } else {
            parent::update($state);
        }

        GeneralUtility::makeInstance(PersistenceManager::class)->persistAll();
    }


    /**
     * Creates or updates a Shopware state record.
     *
     * @param int $connectorUid Connector uid.
     * @param int $indexerUid Indexer uid.
     * @param array<string,mixed> $data State data.
     * @return ShopwareState State record.
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException
     */
    public function upsert(int $connectorUid, int $indexerUid, array $data): ShopwareState
    {
        $state = $this->findByConnectorAndIndexer($connectorUid, $indexerUid)
            ?? GeneralUtility::makeInstance(ShopwareState::class);

        if ($state->getUid() === null) {
            $state->setPid(0);
            $state->setConnectorUid($connectorUid);
            $state->setIndexerUid($indexerUid);
        }

        foreach ($data as $key => $value) {
            $state[$key] = $value;
        }

        if ($state->getUid() === null) {
            parent::add($state);
        } else {
            $this->update($state);
        }
        GeneralUtility::makeInstance(PersistenceManager::class)->persistAll();

        return $state;
    }
}
