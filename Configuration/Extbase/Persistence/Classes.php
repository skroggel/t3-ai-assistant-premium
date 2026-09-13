<?php
declare(strict_types=1);

return [
    \GeorgRinger\News\Domain\Model\News::class => [
        'tableName' => 'tx_news_domain_model_news',
        'subclasses' => [
            0 => \Madj2k\SiteDefault\Domain\Model\News::class,
        ],
    ],
    \Madj2k\AiAssistantPremium\Indexing\Domain\Model\ShopwareState::class => [
        'tableName' => 'tx_aiassistant_indexer_shopware_state',
    ],
];
