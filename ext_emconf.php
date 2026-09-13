<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI Assistant Premium',
    'description' => 'Premium integrations for the TYPO3 AI Assistant extension.',
    'category' => 'plugin',
    'author' => 'Steffen Kroggel, Maximilian Fäßler',
    'author_email' => 'developer@steffenkroggel.de, maximilian@faesslerweb.de',
    'state' => 'beta',
    'clearCacheOnLoad' => true,
    'version' => '2.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'ai_assistant' => '2.0.0-2.99.99',
        ],
        'suggests' => [
            'ke_search' => '',
        ],
        'conflicts' => [],
    ],
];
