<?php
declare(strict_types=1);

$table = 'tx_aiassistant_assistant_pipeline_step';
$processor = 'ai_assistant_premium.search_result_retriever';
$ll = 'LLL:EXT:ai_assistant_premium/Resources/Private/Language/locallang_tx_aiassistant_assistant_pipeline_step.xlf:';

$GLOBALS['TCA'][$table]['columns']['processor_identifier']['onChange'] = 'reload';

$GLOBALS['TCA'][$table]['columns']['max_chunks_per_result'] = [
    'label' => $ll . 'tx_aiassistant_assistant_pipeline_step.max_chunks_per_result',
    'description' => $ll . 'tx_aiassistant_assistant_pipeline_step.max_chunks_per_result.description',
    'displayCond' => 'FIELD:processor_identifier:=:' . $processor,
    'config' => [
        'type' => 'number',
        'format' => 'integer',
        'default' => 1,
    ],
];

$GLOBALS['TCA'][$table]['palettes']['retrieval']['showitem'] = str_replace(
    'max_chunk_characters, --linebreak--, prompt_metadata_fields',
    'max_chunks_per_result, --linebreak--, max_chunk_characters, --linebreak--, prompt_metadata_fields',
    $GLOBALS['TCA'][$table]['palettes']['retrieval']['showitem'],
);
