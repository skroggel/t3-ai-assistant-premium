<template>
    <span hidden aria-hidden="true"></span>
</template>

<script setup>
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * @typedef {Object} AiAssistantSearchJsonEnhancerProps
 * @property {string} integration Search integration identifier.
 * @property {string|number} assistantProfile Assistant profile used for optimization.
 * @property {string} chatIdentifier Stable search/chat identifier.
 * @property {string} requestToken Signed frontend request token.
 * @property {string} endpoint Generic assistant JSON endpoint.
 */

/** @type {import('vue').DefineProps<AiAssistantSearchJsonEnhancerProps>} */
const props = defineProps({
    integration: { type: String, default: '' },
    assistantProfile: { type: [String, Number], default: 0 },
    chatIdentifier: { type: String, default: '' },
    requestToken: { type: String, default: '' },
    endpoint: { type: String, default: '' },
});

/** @type {string} Premium search middleware parameter namespace. */
const controlParameter = 'tx_aiassistantpremium_search';
const apiName = 'AiAssistantPremiumSearch';
let api = null;

/**
 * Executes a JSON request with the shared premium control namespace.
 *
 * @param {string} url Request URL.
 * @param {FormData} formData Request payload.
 * @return {Promise<Record<string, any>>} JSON response.
 */
const requestJson = async (url, formData) => {
    const response = await fetch(url, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    let payload = {};
    try {
        payload = await response.json();
    } catch (error) {
        payload = {};
    }

    if (!response.ok) {
        throw new Error(payload?.error || `Premium JSON request failed with status ${response.status}`);
    }

    return payload;
};

/**
 * Runs one complete assistant turn through the generic JSON endpoint.
 *
 * @param {{query: string, settingsJson?: string, userLanguage?: string}} options Assistant request options.
 * @return {Promise<Record<string, any>>} Assistant response.
 */
const assistant = async ({ query, settingsJson = '{}', userLanguage = '' } = {}) => {
    if (!props.endpoint) {
        throw new Error('The AI Assistant JSON endpoint is not configured.');
    }

    const formData = new FormData();
    formData.set(`${controlParameter}[query]`, query || '');
    formData.set(`${controlParameter}[assistantProfile]`, String(props.assistantProfile));
    formData.set(`${controlParameter}[chatIdentifier]`, props.chatIdentifier);
    formData.set(`${controlParameter}[startTimestamp]`, String(Math.floor(Date.now() / 1000)));
    formData.set(`${controlParameter}[settingsJson]`, settingsJson);
    formData.set(`${controlParameter}[requestToken]`, props.requestToken);
    formData.set(`${controlParameter}[userLanguage]`, userLanguage);

    return requestJson(props.endpoint, formData);
};

/**
 * Optimizes a search request through the premium middleware.
 *
 * @param {{searchQuery: string}} options Optimization request options.
 * @return {Promise<Record<string, any>>} Normalized optimization response.
 */
const optimize = async ({ searchQuery } = {}) => {
    if (typeof searchQuery !== 'string' || searchQuery.trim() === '') {
        throw new Error('A search query is required for AI search optimization.');
    }

    const query = searchQuery.trim();
    const response = await assistant({ query, settingsJson: JSON.stringify({ search: { phase: 'query-optimization', integration: props.integration } }) });
    return {
        ...response,
        effectiveQuery: response.context?.currentQuery || response.answer || query,
    };
};

onMounted(() => {
    api = { assistant, optimize };
    window[apiName] = api;
    window.dispatchEvent(new CustomEvent('ai-assistant-premium-search-ready'));
});

onBeforeUnmount(() => {
    if (window[apiName] === api) {
        delete window[apiName];
    }
});
</script>
