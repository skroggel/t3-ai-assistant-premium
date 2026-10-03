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


namespace Madj2k\AiAssistantPremium\Command;

use Madj2k\AiAssistant\Indexing\Command\IndexingCommandRunner;
use Madj2k\AiCore\Indexing\DTO\IndexingRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'aiassistant:shopware:cleanup-deleted',
    description: 'Remove locally indexed Shopware products that no longer exist in Shopware.'
)]
/**
 * Class ShopwareCleanupDeletedCommand
 *
 * Console entrypoint for Shopware cleanup. The command only builds a request and delegates to the indexing domain.
 *
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final class ShopwareCleanupDeletedCommand extends Command
{
    /**
     * Constructor.
     *
     * @param \Madj2k\AiAssistant\Indexing\Command\IndexingCommandRunner $indexingCommandRunner Indexing command runner.
     */
    public function __construct(
        private readonly IndexingCommandRunner $indexingCommandRunner
    ) {
        parent::__construct();
    }


    /**
     * Configures the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->addOption('indexer', null, InputOption::VALUE_OPTIONAL, 'Indexer configuration uid to use')
            ->addOption('collection', null, InputOption::VALUE_OPTIONAL, 'Override target collection')
            ->addOption('cursor', null, InputOption::VALUE_OPTIONAL, 'Explicit cleanup cursor')
            ->addOption('reset-cursor', null, InputOption::VALUE_NONE, 'Ignore the stored cleanup cursor and start from the beginning')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be removed without writing')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of indexed sources to verify in this batch', 100)
            ->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Number of product ids to verify per Shopware API request', 100)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Return machine-readable JSON output');
    }


    /**
     * Executes the command.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input Input.
     * @param \Symfony\Component\Console\Output\OutputInterface $output Output.
     * @return int Command status.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $request = new IndexingRequest();
        $request->setSourceType('external');
        $request->setIndexerUid($input->getOption('indexer') !== null ? (int)$input->getOption('indexer') : null);
        $request->setCollection((string)($input->getOption('collection') ?? ''));
        $request->setCursor((string)($input->getOption('cursor') ?? ''));
        $request->setResetCursor((bool)$input->getOption('reset-cursor'));
        $request->setDryRun((bool)$input->getOption('dry-run'));
        $request->setLimit(max(1, (int)$input->getOption('limit')));
        $request->setOptions([
            'mode' => 'cleanup_deleted',
            'batch_size' => max(1, (int)$input->getOption('batch-size')),
        ]);

        try {
            $result = $this->indexingCommandRunner->run('aiassistant.indexer.shopware', $request);
        } catch (\Throwable $exception) {
            if ((bool)$input->getOption('json')) {
                $output->writeln((string)json_encode([
                    'status' => 'error',
                    'source_type' => 'external',
                    'indexer_uid' => $request->getIndexerUid(),
                    'limit' => $request->getLimit(),
                    'cursor' => $request->getCursor(),
                    'error' => $exception->getMessage(),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $output->writeln('<error>Cleanup failed: ' . $exception->getMessage() . '</error>');
            }

            return Command::FAILURE;
        }

        if ((bool)$input->getOption('json')) {
            $output->writeln((string)json_encode([
                'status' => 'ok',
                'source_type' => 'external',
                'indexer_uid' => $request->getIndexerUid(),
                'limit' => $request->getLimit(),
                'cursor' => $request->getCursor(),
                'result' => $result->toArray(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            'Processed: %d, Removed: %d, Skipped: %d, Failed: %d, Next cursor: %s, Has more: %s',
            $result->getProcessed(),
            $result->getRemoved(),
            $result->getSkipped(),
            $result->getFailed(),
            $result->getNextCursor() !== '' ? $result->getNextCursor() : '-',
            $result->hasMore() ? 'yes' : 'no'
        ));

        return Command::SUCCESS;
    }
}
