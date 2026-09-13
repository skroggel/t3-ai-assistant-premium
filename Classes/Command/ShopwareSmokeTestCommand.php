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

use Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig;
use Madj2k\AiAssistant\Indexing\Domain\Repository\IndexerConfigRepository;
use Madj2k\AiAssistantPremium\Indexing\Connector\ShopwareConnectorInterface;
use Madj2k\AiCore\Indexing\Resolver\IndexingConnectorResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'aiassistant:shopware:test',
    description: 'Run a read-only smoke test against the Shopware API.'
)]
/**
 * Class ShopwareSmokeTestCommand
 *
 * Console entrypoint for a read-only Shopware API smoke test.
 *
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
final class ShopwareSmokeTestCommand extends Command
{
    /**
     * Constructor.
     *
     * @param \Madj2k\AiCore\Indexing\Resolver\IndexingConnectorResolver $connectorRegistry Connector registry.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Repository\IndexerConfigRepository $indexerConfigRepository Indexer config repository.
     */
    public function __construct(
        private readonly IndexingConnectorResolver $connectorRegistry,
        private readonly IndexerConfigRepository $indexerConfigRepository
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
            ->addOption('indexer', null, InputOption::VALUE_OPTIONAL, 'Shopware indexer configuration uid to test')
            ->addOption('since', null, InputOption::VALUE_OPTIONAL, 'ISO timestamp for updatedAt lower bound in UTC')
            ->addOption('days', null, InputOption::VALUE_OPTIONAL, 'Fallback time window in days', 1)
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Preview up to N products, max 25', 5)
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
        try {
            $configuration = $this->resolveConfiguration($input->getOption('indexer') !== null ? (int)$input->getOption('indexer') : null);
            $since = $this->resolveSince($input, $configuration);
            $limit = min(25, max(1, (int)$input->getOption('limit')));
            $clientConfiguration = $this->buildClientConfiguration($configuration);

            $token = $this->getShopwareConnector()->fetchAccessToken($clientConfiguration);
            if ($token === '') {
                $output->writeln('<error>Shopware OAuth failed: no access token returned.</error>');
                return Command::FAILURE;
            }

            $response = $this->getShopwareConnector()->fetchProducts($since, 1, $limit, $clientConfiguration);
            /** @var array<int, mixed> $products */
            $products = is_array($response['data'] ?? null) ? $response['data'] : [];

            if ((bool)$input->getOption('json')) {
                $output->writeln((string)json_encode([
                    'status' => 'ok',
                    'indexer_uid' => (int)$configuration->getUid(),
                    'since' => $since->format(\DateTimeInterface::ATOM),
                    'returned' => count($products),
                    'products' => $this->buildJsonPreview($products),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return Command::SUCCESS;
            }

            $output->writeln(sprintf(
                'Shopware API reachable. Indexer: %d. Since: %s. Returned: %d item(s).',
                (int)$configuration->getUid(),
                $since->format(\DateTimeInterface::ATOM),
                count($products)
            ));

            foreach ($this->buildHumanPreview($products) as $line) {
                $output->writeln($line);
            }

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>Shopware smoke test failed: ' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }


    /**
     * Resolves the indexer configuration.
     *
     * @param int|null $indexerUid Indexer uid.
     * @return \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig Configuration.
     */
    private function resolveConfiguration(?int $indexerUid): IndexerConfig
    {
        if (($indexerUid ?? 0) > 0) {
            $configuration = $this->indexerConfigRepository->findEnabledByUid((int)$indexerUid);
            if ($configuration instanceof IndexerConfig) {
                return $configuration;
            }
        }

        $configurations = $this->indexerConfigRepository->findEnabledByIndexerIdentifier('aiassistant.indexer.shopware');
        $configuration = $configurations[0] ?? null;
        if ($configuration instanceof IndexerConfig) {
            return $configuration;
        }

        throw new \InvalidArgumentException('No enabled Shopware indexer configuration found.', 1760005001);
    }


    /**
     * Resolves the since date.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input Input.
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Configuration.
     * @return \DateTimeImmutable Since date.
     */
    private function resolveSince(InputInterface $input, IndexerConfig $configuration): \DateTimeImmutable
    {
        $since = trim((string)($input->getOption('since') ?? ''));
        if ($since !== '') {
            return (new \DateTimeImmutable($since))->setTimezone(new \DateTimeZone('UTC'));
        }

        $days = (int)($input->getOption('days') ?? 0);
        if ($days <= 0) {
            $days = $configuration->getShopwareLookbackDays() > 0 ? $configuration->getShopwareLookbackDays() : 1;
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . $days . ' days');
    }


    /**
     * Builds a JSON preview.
     *
     * @param array<int, mixed> $products Products.
     * @return array<int, array<string, mixed>> Preview.
     */
    private function buildJsonPreview(array $products): array
    {
        /** @var array<int, array<string, mixed>> $preview */
        $preview = [];

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $preview[] = [
                'id' => (string)($product['id'] ?? ''),
                'name' => (string)($product['translated']['name'] ?? $product['name'] ?? ''),
                'productNumber' => (string)($product['productNumber'] ?? ''),
                'active' => (bool)($product['active'] ?? true),
                'updatedAt' => (string)($product['updatedAt'] ?? ''),
            ];
        }

        return $preview;
    }


    /**
     * Builds a human readable preview.
     *
     * @param array<int, mixed> $products Products.
     * @return array<int, string> Lines.
     */
    private function buildHumanPreview(array $products): array
    {
        /** @var array<int, string> $lines */
        $lines = [];

        foreach ($this->buildJsonPreview($products) as $product) {
            $lines[] = sprintf(
                '- %s | %s | %s | active: %s',
                $product['id'],
                $product['productNumber'],
                $product['name'],
                $product['active'] ? 'yes' : 'no'
            );
        }

        return $lines;
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
            throw new \UnexpectedValueException('Registered Shopware connector does not implement ShopwareConnectorInterface.', 1760001103);
        }

        return $connector;
    }


    /**
     * Builds the client configuration for the Shopware connector.
     *
     * @param \Madj2k\AiAssistant\Indexing\Domain\Model\IndexerConfig $configuration Indexer configuration.
     * @return array<string, mixed> Client configuration.
     */
    private function buildClientConfiguration(IndexerConfig $configuration): array
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

}
