<?php
declare(strict_types=1);

$ll = 'LLL:EXT:ai_assistant_premium/Resources/Private/Language/locallang_tx_aiassistant_indexer.xlf:';

$GLOBALS['TCA']['tx_aiassistant_indexer']['columns'] = array_merge(
    $GLOBALS['TCA']['tx_aiassistant_indexer']['columns'],
    [
        'shopware_base_url' => [
            'label' => $ll . 'tx_aiassistant_indexer.shopware_base_url',
            'description' => $ll . 'tx_aiassistant_indexer.shopware_base_url_desc',
            'config' => [
                'type' => 'input',
                'placeholder' => $ll . 'tx_aiassistant_indexer.shopware_base_url_placeholder',
                'eval' => 'trim',
                'max' => 512,
                'size' => 60,
            ],
        ],
        'shopware_download_base_url' => [
            'label' => $ll . 'tx_aiassistant_indexer.shopware_download_base_url',
            'description' => $ll . 'tx_aiassistant_indexer.shopware_download_base_url_desc',
            'config' => [
                'type' => 'input',
                'placeholder' => $ll . 'tx_aiassistant_indexer.shopware_download_base_url_placeholder',
                'eval' => 'trim',
                'max' => 512,
                'size' => 60,
            ],
        ],
        'shopware_download_path' => [
            'label' => $ll . 'tx_aiassistant_indexer.shopware_download_path',
            'description' => $ll . 'tx_aiassistant_indexer.shopware_download_path_desc',
            'config' => [
                'type' => 'input',
                'placeholder' => $ll . 'tx_aiassistant_indexer.shopware_download_path_placeholder',
                'eval' => 'trim',
                'max' => 512,
                'size' => 60,
            ],
        ],
        'shopware_client_id' => [
            'label' => $ll . 'tx_aiassistant_indexer.shopware_client_id',
            'description' => $ll . 'tx_aiassistant_indexer.shopware_client_id_desc',
            'config' => [
                'type' => 'input',
                'placeholder' => $ll . 'tx_aiassistant_indexer.shopware_client_id_placeholder',
                'eval' => 'trim',
                'max' => 512,
                'size' => 60,
            ],
        ],
        'shopware_api_key' => [
            'label' => $ll . 'tx_aiassistant_indexer.shopware_api_key',
            'description' => $ll . 'tx_aiassistant_indexer.shopware_api_key_desc',
            'config' => [
                'type' => 'password',
                'placeholder' => $ll . 'tx_aiassistant_indexer.shopware_api_key_placeholder',
                'eval' => 'trim',
                'max' => 1024,
                'size' => 40,
                'passwordGenerator' => false,
                'hashed' => false,
            ],
        ],
        'shopware_verify_tls' => [
            'label' => $ll . 'tx_aiassistant_indexer.shopware_verify_tls',
            'description' => $ll . 'tx_aiassistant_indexer.shopware_verify_tls_desc',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
            ],
        ],
        'shopware_lookback_days' => [
            'label' => $ll . 'tx_aiassistant_indexer.shopware_lookback_days',
            'description' => $ll . 'tx_aiassistant_indexer.shopware_lookback_days_desc',
            'config' => [
                'type' => 'number',
                'placeholder' => $ll . 'tx_aiassistant_indexer.shopware_lookback_days_placeholder',
                'default' => 1,
                'format' => 'integer',
            ],
        ],
        'shopware_custom_fields' => [
            'label' => $ll . 'tx_aiassistant_indexer.shopware_custom_fields',
            'description' => $ll . 'tx_aiassistant_indexer.shopware_custom_fields_desc',
            'config' => [
                'type' => 'text',
                'placeholder' => $ll . 'tx_aiassistant_indexer.shopware_custom_fields_placeholder',
                'default' => 'custom_product_group, custom_manufacturer_text',
                'rows' => 3,
            ],
        ],
        'shopware_indexed_fields' => [
            'label' => $ll . 'tx_aiassistant_indexer.shopware_indexed_fields',
            'description' => $ll . 'tx_aiassistant_indexer.shopware_indexed_fields_desc',
            'config' => [
                'type' => 'text',
                'placeholder' => $ll . 'tx_aiassistant_indexer.shopware_indexed_fields_placeholder',
                'default' => 'translated.name, translated.description, productNumber',
                'rows' => 3,
            ],
        ],

    ]
);

$GLOBALS['TCA']['tx_aiassistant_indexer']['palettes'] = array_merge(
    $GLOBALS['TCA']['tx_aiassistant_indexer']['palettes'],
    [
        'shopware' => [
            'showitem' => 'shopware_base_url, --linebreak--, shopware_download_base_url, --linebreak--, shopware_download_path, --linebreak--,
                           shopware_client_id,  --linebreak--,shopware_api_key, --linebreak--, shopware_verify_tls, --linebreak--, shopware_lookback_days, --linebreak--,
                           shopware_custom_fields,  --linebreak--,shopware_indexed_fields',
        ]
    ]
);
