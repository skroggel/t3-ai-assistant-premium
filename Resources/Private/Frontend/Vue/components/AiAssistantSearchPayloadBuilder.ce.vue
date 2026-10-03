<template>
    <span hidden aria-hidden="true"></span>
</template>

<script setup>
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * @typedef {Object} AiAssistantSearchPayloadBuilderProps
 * @property {string} rowsJson JSON encoded search result rows.
 * @property {string} idField Source identifier field.
 * @property {string} titleField Result title field.
 * @property {string} textField Result text field.
 * @property {string} urlField Result URL field.
 * @property {string} typeField Source type field.
 * @property {string} pageIdField Source page ID field.
 * @property {string} changedAtField Source timestamp field.
 * @property {string} scoreField Relevance score field.
 */

/** @type {import('vue').DefineProps<AiAssistantSearchPayloadBuilderProps>} */
const props = defineProps({
    rowsJson: { type: String, default: '[]' },
    idField: { type: String, default: '' },
    titleField: { type: String, default: '' },
    textField: { type: String, default: '' },
    urlField: { type: String, default: '' },
    typeField: { type: String, default: '' },
    pageIdField: { type: String, default: '' },
    changedAtField: { type: String, default: '' },
    scoreField: { type: String, default: '' },
});

/** @type {string} Public API name used by other Premium custom elements. */
const apiName = 'AiAssistantPremiumSearchPayloadBuilder';
const readyEventName = 'ai-assistant-premium-search-payload-builder-ready';
let api = null;
let emittedPayload = null;

/**
 * Reads a scalar value from a result row.
 *
 * @param {Object} row Search result row.
 * @param {string} field Field name or dot-separated field path.
 * @return {*} Field value.
 */
const readField = (row, field) => {
    if (!field) return '';
    return field.split('.').reduce((value, key) => value?.[key], row) ?? '';
};

/**
 * Converts an arbitrary value to bounded plain text.
 *
 * @param {*} value Value to normalize.
 * @param {number} maximumLength Maximum output length.
 * @return {string} Normalized plain text.
 */
const normalizeText = (value, maximumLength) => {
    const container = document.createElement('div');
    container.innerHTML = String(value ?? '');
    return (container.textContent || '').replace(/\s+/gu, ' ').trim().slice(0, maximumLength);
};

/**
 * Returns the configured field mapping.
 *
 * @param {Object} fields Field mapping.
 * @return {Object} Normalized field mapping.
 */
const normalizeFields = (fields = {}) => ({
    id: fields.id || props.idField,
    title: fields.title || props.titleField,
    text: fields.text || props.textField,
    url: fields.url || props.urlField,
    type: fields.type || props.typeField,
    pageId: fields.pageId || props.pageIdField,
    changedAt: fields.changedAt || props.changedAtField,
    score: fields.score || props.scoreField,
});

/**
 * Builds the engine-neutral search payload used by the Assistant pipeline.
 *
 * @param {Object} options Builder options.
 * @param {Object[]} options.rows Search result rows.
 * @param {Object} options.fields Search field mapping.
 * @param {number} [options.total] Total result count.
 * @return {Object} Canonical captured-results payload.
 */
const build = ({
    rows = [],
    fields = {},
    total,
} = {}) => {
    const mapping = normalizeFields(fields);
    const results = (Array.isArray(rows) ? rows : []).map((row, position) => {
        const sourceType = normalizeText(readField(row, mapping.type), 100);
        const sourceUid = normalizeText(readField(row, mapping.id), 255);
        const identifier = sourceUid
            ? `${sourceType ? `${sourceType}:` : ''}${sourceUid}`
            : `result:${position + 1}`;
        const scoreValue = readField(row, mapping.score);
        const score = Number.isFinite(Number(scoreValue))
            ? Number(scoreValue)
            : Math.max(0, 1 - (position * 0.01));

        return {
            id: identifier,
            score: score,
            text: normalizeText(readField(row, mapping.text), 6000),
            source_type: sourceType,
            source_identifier: identifier,
            source_uid: sourceUid,
            title: normalizeText(readField(row, mapping.title), 500),
            url: normalizeText(readField(row, mapping.url), 2048),
            page_id: Number(readField(row, mapping.pageId)) || 0,
            changed_at: Number(readField(row, mapping.changedAt)) || 0,
            position: position + 1,
        };
    });

    return {
        version: 1,
        total: Math.max(0, Number(total) || results.length),
        results,
    };
};

/**
 * Builds and publishes a payload for the Summary custom element.
 *
 * @param {Object} options Builder options.
 * @param {Object} [options.context] Current search query context.
 * @return {Object} Published canonical result payload.
 */
const publish = ({ context = {}, ...options } = {}) => {
    const payload = build(options);
    window.AiAssistantPremiumSearchPayload = payload;
    window.AiAssistantPremiumSearchContext = context;
    window.dispatchEvent(new CustomEvent('ai-assistant-search-payload-ready', {
        detail: { payload, context },
    }));
    return payload;
};

/**
 * Parses the static rows provided by the classic Fluid integration.
 *
 * @return {Object[]} Parsed result rows.
 */
const getInitialRows = () => {
    try {
        const rows = JSON.parse(props.rowsJson || '[]');
        return Array.isArray(rows) ? rows : [];
    } catch (error) {
        return [];
    }
};

onMounted(() => {
    api = { build, publish };
    window[apiName] = api;
    window.dispatchEvent(new CustomEvent(readyEventName));

    const rows = getInitialRows();
    if (rows.length > 0) {
        emittedPayload = publish({ rows });
    }
});

onBeforeUnmount(() => {
    if (window.AiAssistantPremiumSearchPayload === emittedPayload) {
        delete window.AiAssistantPremiumSearchPayload;
    }
    if (window[apiName] === api) {
        delete window[apiName];
    }
});
</script>
