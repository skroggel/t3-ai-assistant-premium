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

namespace Madj2k\AiAssistantPremium\Indexing\Domain\Model;


use Madj2k\AiAssistant\Indexing\Domain\Model\AbstractEntity;

/**
 * Class ShopwareState
 *
 * Domain object for the tx_aiassistant_indexer_shopware_state record.
 *
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
class ShopwareState extends AbstractEntity
{

    /**
     * @var int
     */
    protected int $connectorUid = 0;


    /**
     * @var int
     */
    protected int $indexerUid = 0;


    /**
     * @var int
     */
    protected int $cursorUpdatedAt = 0;


    /**
     * @var string
     */
    protected string $cursorSourceId = '';


    /**
     * @var string
     */
    protected string $cleanupCursorSourceId = '';


    /**
     * @var int
     */
    protected int $cleanupLastRunAt = 0;


    /**
     * @var int
     */
    protected int $lastRunStartedAt = 0;


    /**
     * @var int
     */
    protected int $lastRunFinishedAt = 0;


    /**
     * @var string
     */
    protected string $status = '';


    /**
     * @var string
     */
    protected string $lastError = '';


    /**
     * @var int
     */
    protected int $itemsProcessed = 0;


    /**
     * @var int
     */
    protected int $itemsIndexed = 0;


    /**
     * @var int
     */
    protected int $itemsSkipped = 0;


    /**
     * @var int
     */
    protected int $itemsFailed = 0;


    /**
     * Returns connector_uid.
     *
     * @return int connector_uid.
     */
    public function getConnectorUid(): int
    {
        return $this->connectorUid;
    }


    /**
     * Sets connector_uid.
     *
     * @param int $connectorUid connector_uid.
     * @return void
     */
    public function setConnectorUid(int $connectorUid): void
    {
        $this->connectorUid = $connectorUid;
    }


    /**
     * Returns indexer_uid.
     *
     * @return int indexer_uid.
     */
    public function getIndexerUid(): int
    {
        return $this->indexerUid;
    }


    /**
     * Sets indexer_uid.
     *
     * @param int $indexerUid indexer_uid.
     * @return void
     */
    public function setIndexerUid(int $indexerUid): void
    {
        $this->indexerUid = $indexerUid;
    }


    /**
     * Returns cursor_updated_at.
     *
     * @return int cursor_updated_at.
     */
    public function getCursorUpdatedAt(): int
    {
        return $this->cursorUpdatedAt;
    }


    /**
     * Sets cursor_updated_at.
     *
     * @param int $cursorUpdatedAt cursor_updated_at.
     * @return void
     */
    public function setCursorUpdatedAt(int $cursorUpdatedAt): void
    {
        $this->cursorUpdatedAt = $cursorUpdatedAt;
    }


    /**
     * Returns cursor_source_id.
     *
     * @return string cursor_source_id.
     */
    public function getCursorSourceId(): string
    {
        return $this->cursorSourceId;
    }


    /**
     * Sets cursor_source_id.
     *
     * @param string $cursorSourceId cursor_source_id.
     * @return void
     */
    public function setCursorSourceId(string $cursorSourceId): void
    {
        $this->cursorSourceId = $cursorSourceId;
    }


    /**
     * Returns cleanup_cursor_source_id.
     *
     * @return string cleanup_cursor_source_id.
     */
    public function getCleanupCursorSourceId(): string
    {
        return $this->cleanupCursorSourceId;
    }


    /**
     * Sets cleanup_cursor_source_id.
     *
     * @param string $cleanupCursorSourceId cleanup_cursor_source_id.
     * @return void
     */
    public function setCleanupCursorSourceId(string $cleanupCursorSourceId): void
    {
        $this->cleanupCursorSourceId = $cleanupCursorSourceId;
    }


    /**
     * Returns cleanup_last_run_at.
     *
     * @return int cleanup_last_run_at.
     */
    public function getCleanupLastRunAt(): int
    {
        return $this->cleanupLastRunAt;
    }


    /**
     * Sets cleanup_last_run_at.
     *
     * @param int $cleanupLastRunAt cleanup_last_run_at.
     * @return void
     */
    public function setCleanupLastRunAt(int $cleanupLastRunAt): void
    {
        $this->cleanupLastRunAt = $cleanupLastRunAt;
    }


    /**
     * Returns last_run_started_at.
     *
     * @return int last_run_started_at.
     */
    public function getLastRunStartedAt(): int
    {
        return $this->lastRunStartedAt;
    }


    /**
     * Sets last_run_started_at.
     *
     * @param int $lastRunStartedAt last_run_started_at.
     * @return void
     */
    public function setLastRunStartedAt(int $lastRunStartedAt): void
    {
        $this->lastRunStartedAt = $lastRunStartedAt;
    }


    /**
     * Returns last_run_finished_at.
     *
     * @return int last_run_finished_at.
     */
    public function getLastRunFinishedAt(): int
    {
        return $this->lastRunFinishedAt;
    }


    /**
     * Sets last_run_finished_at.
     *
     * @param int $lastRunFinishedAt last_run_finished_at.
     * @return void
     */
    public function setLastRunFinishedAt(int $lastRunFinishedAt): void
    {
        $this->lastRunFinishedAt = $lastRunFinishedAt;
    }


    /**
     * Returns status.
     *
     * @return string status.
     */
    public function getStatus(): string
    {
        return $this->status;
    }


    /**
     * Sets status.
     *
     * @param string $status status.
     * @return void
     */
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }


    /**
     * Returns last_error.
     *
     * @return string last_error.
     */
    public function getLastError(): string
    {
        return $this->lastError;
    }


    /**
     * Sets last_error.
     *
     * @param string $lastError last_error.
     * @return void
     */
    public function setLastError(string $lastError): void
    {
        $this->lastError = $lastError;
    }


    /**
     * Returns items_processed.
     *
     * @return int items_processed.
     */
    public function getItemsProcessed(): int
    {
        return $this->itemsProcessed;
    }


    /**
     * Sets items_processed.
     *
     * @param int $itemsProcessed items_processed.
     * @return void
     */
    public function setItemsProcessed(int $itemsProcessed): void
    {
        $this->itemsProcessed = $itemsProcessed;
    }


    /**
     * Returns items_indexed.
     *
     * @return int items_indexed.
     */
    public function getItemsIndexed(): int
    {
        return $this->itemsIndexed;
    }


    /**
     * Sets items_indexed.
     *
     * @param int $itemsIndexed items_indexed.
     * @return void
     */
    public function setItemsIndexed(int $itemsIndexed): void
    {
        $this->itemsIndexed = $itemsIndexed;
    }


    /**
     * Returns items_skipped.
     *
     * @return int items_skipped.
     */
    public function getItemsSkipped(): int
    {
        return $this->itemsSkipped;
    }


    /**
     * Sets items_skipped.
     *
     * @param int $itemsSkipped items_skipped.
     * @return void
     */
    public function setItemsSkipped(int $itemsSkipped): void
    {
        $this->itemsSkipped = $itemsSkipped;
    }


    /**
     * Returns items_failed.
     *
     * @return int items_failed.
     */
    public function getItemsFailed(): int
    {
        return $this->itemsFailed;
    }


    /**
     * Sets items_failed.
     *
     * @param int $itemsFailed items_failed.
     * @return void
     */
    public function setItemsFailed(int $itemsFailed): void
    {
        $this->itemsFailed = $itemsFailed;
    }
}
