<?php
declare(strict_types=1);

$ll = 'LLL:EXT:ai_assistant_premium/Resources/Private/Language/locallang_tx_aiassistant_indexer_shopware_state.xlf:';

return [
    'ctrl' => [
        'title' => $ll . 'tx_aiassistant_indexer_shopware_state',
        'label' => 'indexer_uid',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'searchFields' => 'connector_uid,indexer_uid,cursor_source_id,cleanup_cursor_source_id,status',
        'iconfile' => 'EXT:ai_assistant/Resources/Public/Icons/Extension.svg',
    ],
    'types' => [
        '1' => [
            'showitem' => 'connector_uid, indexer_uid, cursor_updated_at, cursor_source_id, cleanup_cursor_source_id, cleanup_last_run_at, last_run_started_at, last_run_finished_at, status, last_error, items_processed, items_indexed, items_skipped, items_failed',
        ]
    ],
    'columns' => [
        'connector_uid' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.connector_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
                'format' => 'integer',
            ],
        ],
        'indexer_uid' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.indexer_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
                'format' => 'integer',
            ],
        ],
        'cursor_updated_at' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.cursor_updated_at',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
                'format' => 'integer',
            ],
        ],
        'cursor_source_id' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.cursor_source_id',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
                'eval' => 'trim',
                'max' => 64,
            ],
        ],
        'cleanup_cursor_source_id' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.cleanup_cursor_source_id',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
                'eval' => 'trim',
                'max' => 64,
            ],
        ],
        'cleanup_last_run_at' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.cleanup_last_run_at',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
                'format' => 'integer',
            ],
        ],
        'last_run_started_at' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.last_run_started_at',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
                'format' => 'integer',
            ],
        ],
        'last_run_finished_at' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.last_run_finished_at',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
                'format' => 'integer',
            ],
        ],
        'status' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.status',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
                'eval' => 'trim',
                'max' => 16,
            ],
        ],
        'last_error' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.last_error',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
                'rows' => 5,
            ],
        ],
        'items_processed' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.items_processed',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
                'format' => 'integer',
            ],
        ],
        'items_indexed' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.items_indexed',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
                'format' => 'integer',
            ],
        ],
        'items_skipped' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.items_skipped',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
                'format' => 'integer',
            ],
        ],
        'items_failed' => [
            'label' => $ll . 'tx_aiassistant_indexer_shopware_state.items_failed',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
                'format' => 'integer',
            ],
        ]
    ],
];
