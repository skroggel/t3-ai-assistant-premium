<template>
    <section v-if="ready" class="aiassistant-summary-container">
        <ai-assistant-chat
            :key="summaryVersion"
            :endpoint="sseEndpoint"
            :assistant-profile="assistantProfile"
            :chat-identifier="chatIdentifier"
            :request-token="requestToken"
            :start-timestamp="startTimestamp"
            :settings-json="runtimeSettings"
            :chat-options-json="chatOptionsJson"
            :auto-query="summaryAnswer ? '' : summaryQuery"
            :initial-message="summaryAnswer"
            :labels-json="labelsJson"
        ></ai-assistant-chat>
    </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * @typedef {Object} AiAssistantSearchSummaryProps
 * @property {string} sseEndpoint AI Assistant SSE endpoint for follow-up messages.
 * @property {string} integration Search integration identifier.
 * @property {string} query Original search query used for the summary.
 * @property {string|number} assistantProfile Assistant profile uid.
 * @property {string} chatIdentifier Stable search/chat identifier.
 * @property {string} requestToken Signed frontend request token.
 * @property {string|number} startTimestamp Session reset timestamp.
 * @property {string} settingsJson Serialized runtime settings.
 * @property {string} chatOptionsJson Serialized normalized frontend chat options.
 * @property {string} labelsJson Serialized translated labels for the nested chat.
 */

/** @type {import('vue').DefineProps<AiAssistantSearchSummaryProps>} */
const props = defineProps({
    // Request identity and search context.
    sseEndpoint: { type: String, default: '' },
    integration: { type: String, default: 'ke_search' },
    query: { type: String, default: '' },
    assistantProfile: { type: [String, Number], default: 0 },
    chatIdentifier: { type: String, default: '' },
    requestToken: { type: String, default: '' },
    startTimestamp: { type: [String, Number], default: 0 },

    // Runtime settings passed to the nested assistant chat.
    settingsJson: { type: String, default: '{}' },
    chatOptionsJson: { type: String, default: '{}' },

    // Translated labels for the nested chat.
    labelsJson: { type: String, default: '{}' },
});

/** @type {import('vue').Ref<boolean>} Whether captured results are ready. */
const ready = ref(false);
const summaryAnswer = ref('');
const summaryVersion = ref(0);
const summaryQuery = ref(props.query || '');

/** @type {import('vue').Ref<string>} Runtime settings enriched with search results. */
const runtimeSettings = ref(props.settingsJson || '{}');

let api = null;

/**
 * Resolves the shared frontend payload builder.
 *
 * @return {Promise<Object|null>} Builder API or null when unavailable.
 */
const getPayloadBuilder = () => {
    if (window.AiAssistantPremiumSearchPayloadBuilder?.build) {
        return Promise.resolve(window.AiAssistantPremiumSearchPayloadBuilder);
    }

    return new Promise((resolve) => {
        const eventName = 'ai-assistant-premium-search-payload-builder-ready';
        const onReady = () => {
            window.removeEventListener(eventName, onReady);
            resolve(window.AiAssistantPremiumSearchPayloadBuilder || null);
        };

        window.addEventListener(eventName, onReady, { once: true });
        window.setTimeout(() => {
            window.removeEventListener(eventName, onReady);
            resolve(window.AiAssistantPremiumSearchPayloadBuilder || null);
        }, 1000);
    });
};

/**
 * Adds the Assistant search context to a normalized result payload.
 *
 * @param {Object} payload Normalized result payload.
 * @param {Object} options Current search context.
 * @return {Object} Canonical captured-results payload.
 */
const addPayloadContext = (payload, options = {}) => {
    const integration = String(props.integration || '').trim();
    const results = (payload.results || []).map((result) => {
        const identifier = `${integration}:${result.id}`;
        return {
            ...result,
            id: identifier,
            source_identifier: identifier,
        };
    });

    return {
        ...payload,
        integration,
        chatIdentifier: props.chatIdentifier,
        originalQuery: options.originalQuery || props.query || '',
        effectiveQuery: options.effectiveQuery || options.originalQuery || props.query || '',
        total: Number(options.total || results.length),
        results,
    };
};

/**
 * Runs the normalized search results through the complete assistant turn.
 *
 * @param {Object} options Search result and query data.
 * @return {Promise<Record<string, any>>} Assistant response.
 */
const summarize = async (options = {}) => {
    ready.value = false;
    summaryAnswer.value = '';
    summaryVersion.value += 1;

    const payloadBuilder = await getPayloadBuilder();
    if (!payloadBuilder?.build) {
        throw new Error('The search payload builder is not available.');
    }

    let settings = {};
    try {
        const parsedSettings = JSON.parse(props.settingsJson || '{}');
        settings = parsedSettings && typeof parsedSettings === 'object' && !Array.isArray(parsedSettings)
            ? parsedSettings
            : {};
    } catch (error) {
        settings = {};
    }

    settings.search = settings.search && typeof settings.search === 'object' && !Array.isArray(settings.search)
        ? settings.search
        : {};
    const payload = payloadBuilder.build({
        rows: Array.isArray(options.payload) ? options.payload : [],
        fields: options.fields || {},
        total: options.total,
    });
    settings.search.capturedResults = addPayloadContext(payload, options);
    runtimeSettings.value = JSON.stringify(settings);
    summaryQuery.value = options.effectiveQuery || options.originalQuery || props.query || '';
    ready.value = true;
    return { context: { currentQuery: summaryQuery.value } };
};

/**
 * Consumes a payload emitted by the classic search integration.
 *
 * @param {*} capturedResults Candidate normalized payload.
 * @param {Object} context Current search query context.
 * @return {boolean} True when the payload was accepted.
 */
const consumeCapturedResults = (capturedResults, context = {}) => {
    if (
        !capturedResults
        || typeof capturedResults !== 'object'
        || Array.isArray(capturedResults)
        || !Array.isArray(capturedResults.results)
        || capturedResults.results.length === 0
    ) {
        return false;
    }

    ready.value = false;
    summaryAnswer.value = '';
    summaryVersion.value += 1;

    let settings = {};
    try {
        const parsedSettings = JSON.parse(props.settingsJson || '{}');
        settings = parsedSettings && typeof parsedSettings === 'object' && !Array.isArray(parsedSettings)
            ? parsedSettings
            : {};
    } catch (error) {
        settings = {};
    }

    settings.search = settings.search && typeof settings.search === 'object' && !Array.isArray(settings.search)
        ? settings.search
        : {};
    const capturedPayload = addPayloadContext(capturedResults, {
        originalQuery: context.originalQuery || capturedResults.originalQuery || props.query,
        effectiveQuery: context.effectiveQuery || capturedResults.effectiveQuery || props.query,
        total: capturedResults.total,
    });
    settings.search.capturedResults = capturedPayload;
    runtimeSettings.value = JSON.stringify(settings);
    summaryQuery.value = capturedPayload.effectiveQuery || capturedPayload.originalQuery || props.query || '';
    ready.value = true;
    return true;
};

/**
 * Mounted
 */
onMounted(() => {
    api = { summarize };
    window.AiAssistantPremiumSearchSummary = api;
    window.dispatchEvent(new CustomEvent('ai-assistant-premium-search-summary-ready'));
    const handlePayload = (event) => {
        const detail = event.detail || {};
        const payload = detail.payload || detail;
        const context = detail.context || window.AiAssistantPremiumSearchContext || {};
        consumeCapturedResults(payload, context);
    };
    window.addEventListener('ai-assistant-search-payload-ready', handlePayload);
    consumeCapturedResults(
        window.AiAssistantPremiumSearchPayload,
        window.AiAssistantPremiumSearchContext || {},
    );
    api.handlePayload = handlePayload;
});

/**
 * Disconnects the payload observer when the custom element is removed.
 *
 * @return {void}
 */
onBeforeUnmount(() => {
    if (api?.handlePayload) {
        window.removeEventListener('ai-assistant-search-payload-ready', api.handlePayload);
    }
    if (window.AiAssistantPremiumSearchSummary === api) {
        delete window.AiAssistantPremiumSearchSummary;
    }
});
</script>
