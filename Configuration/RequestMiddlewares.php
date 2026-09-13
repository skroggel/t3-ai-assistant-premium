<?php
declare(strict_types=1);

use Madj2k\AiAssistantPremium\Search\Middleware\SearchQueryMiddleware;

return [
    'frontend' => [
        'ai-assistant-premium/search-query-optimizer' => [
            'target' => SearchQueryMiddleware::class,
            'after' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
            'before' => [
                'typo3/cms-frontend/shortcut-and-mountpoint-redirect',
                'typo3/cms-frontend/csp-headers',
            ],
        ],
    ],
];
