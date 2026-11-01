<template>
    <section v-if="ready" class="aiassistant-summary-container">
        <component
            :is="resolvedChatComponent"
            :endpoint="streamResponse ? sseEndpoint : jsonEndpoint"
            :stream-response="streamResponse"
            :assistant-profile="assistantProfile"
            :chat-identifier="chatIdentifier"
            :request-token="requestToken"
            :start-timestamp="startTimestamp"
            :settings-json="runtimeSettings"
            :chat-options-json="chatOptionsJson"
            :auto-query="summaryAnswer ? '' : (summaryQuery || query)"
            :initial-message="summaryAnswer"
            :labels-json="labelsJson"
            :ui-components-json="uiComponentsJson"
            :error-handling="errorHandling"
            @error="handleAssistantError"
        />
    </section>
</template>

<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import { summaryProps } from '../summaryProps.js';

const props = defineProps(summaryProps);
/** @type {import('vue').Ref<boolean>} Whether captured results are ready. */
const ready = ref(false);
/** @type {import('vue').Ref<string>} Summary answer available for initial chat content. */
const summaryAnswer = ref('');
/** @type {import('vue').Ref<string>} Current summary query. */
const summaryQuery = ref(props.query || '');
/** @type {import('vue').Ref<string>} Runtime settings enriched with search results. */
const runtimeSettings = ref(props.settingsJson || '{}');

const toBoolean = (value) => {
    if (typeof value === 'string') return ['true', '1'].includes(value.trim().toLowerCase());
    return value === true || value === 1;
};
const streamResponse = toBoolean(props.streamResponse);
const hasDirectIntegration = computed(() => (
    Boolean(props.chatComponent) || Boolean(props.capturedResults)
));
/**
 * Loads the standard chat only when no custom chat component was supplied.
 *
 * This keeps the Integration bundle free from the standard chat code when a
 * site provides its own `chatComponent`, while the regular Vue Summary still
 * gets the standard chat by default.
 */
const defaultChatComponent = defineAsyncComponent(() => import(
    '../../../../../../../t3-ai-assistant/Resources/Private/Frontend/Vue/Src/components/AiAssistantChat.vue'
));
const resolvedChatComponent = computed(() => props.chatComponent || defaultChatComponent);

/**
 * Hides a silent summary when the nested chat cannot produce an answer.
 *
 * @return {void}
 */
const handleAssistantError = () => {
    if (props.errorHandling === 'silent') ready.value = false;
};

let api = null;
let lastCapturedResultsSignature = '';

const getCapturedResultsSignature = (capturedResults) => {
    try {
        return JSON.stringify(capturedResults);
    } catch (error) {
        return '';
    }
};

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

/** @return {Record<string, any>} Parsed runtime settings. */
const parseSettings = () => {
    try {
        const parsed = JSON.parse(props.settingsJson || '{}');
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (error) {
        return {};
    }
};

/**
 * Stores captured results in the runtime settings used by the nested chat.
 *
 * @param {Object} payload Normalized result payload.
 * @param {Object} options Current search context.
 * @return {void}
 */
const applyPayload = (payload, options = {}) => {
    const settings = parseSettings();
    settings.search = settings.search && typeof settings.search === 'object' && !Array.isArray(settings.search)
        ? settings.search
        : {};
    const capturedPayload = addPayloadContext(payload, options);
    settings.search.capturedResults = capturedPayload;
    runtimeSettings.value = JSON.stringify(settings);
    summaryQuery.value = capturedPayload.originalQuery || props.query || '';
    ready.value = true;
};

/**
 * Runs normalized search results through the complete assistant turn.
 *
 * @param {Object} options Search result and query data.
 * @return {Promise<Record<string, any>>} Assistant response.
 */
const summarize = async (options = {}) => {
    ready.value = false;
    summaryAnswer.value = '';
    lastCapturedResultsSignature = '';
    const payloadBuilder = await getPayloadBuilder();
    if (!payloadBuilder?.build) throw new Error('The search payload builder is not available.');
    const payload = payloadBuilder.build({
        rows: Array.isArray(options.payload) ? options.payload : [],
        fields: options.fields || {},
        total: options.total,
    });
    if (!toBoolean(props.showWithoutResults) && payload.results.length === 0) {
        return { context: { currentQuery: summaryQuery.value } };
    }
    applyPayload(payload, options);
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
    ) return false;
    if (!toBoolean(props.showWithoutResults) && capturedResults.results.length === 0) return false;

    const signature = getCapturedResultsSignature(capturedResults);
    if (signature !== '' && signature === lastCapturedResultsSignature) {
        return true;
    }
    lastCapturedResultsSignature = signature;

    ready.value = false;
    summaryAnswer.value = '';
    applyPayload(capturedResults, {
        originalQuery: context.originalQuery || capturedResults.originalQuery || props.query,
        effectiveQuery: context.effectiveQuery || capturedResults.effectiveQuery || props.query,
        total: capturedResults.total,
    });
    return true;
};

watch(
    () => props.capturedResults,
    (capturedResults) => {
        if (!capturedResults) return;
        consumeCapturedResults(capturedResults, {
            originalQuery: capturedResults.originalQuery || props.query,
            effectiveQuery: capturedResults.effectiveQuery || props.query,
            total: capturedResults.total,
        });
    },
    { immediate: true },
);

/** Mounted. */
onMounted(() => {
    api = { summarize };
    window.AiAssistantPremiumSearchSummary = api;
    window.dispatchEvent(new CustomEvent('ai-assistant-premium-search-summary-ready'));
    const handlePayload = (event) => {
        if (hasDirectIntegration.value) return;
        const detail = event.detail || {};
        const payload = detail.payload || detail;
        const context = detail.context || window.AiAssistantPremiumSearchContext || {};
        consumeCapturedResults(payload, context);
    };
    if (!hasDirectIntegration.value) {
        window.addEventListener('ai-assistant-search-payload-ready', handlePayload);
        const consumePublishedPayload = () => consumeCapturedResults(
            window.AiAssistantPremiumSearchPayload,
            window.AiAssistantPremiumSearchContext || {},
        );
        consumePublishedPayload();
        window.setTimeout(consumePublishedPayload, 0);
        window.setTimeout(consumePublishedPayload, 100);
    }
    api.handlePayload = handlePayload;
});

/** Disconnects the payload observer when the custom element is removed. */
onBeforeUnmount(() => {
    if (api?.handlePayload) {
        window.removeEventListener('ai-assistant-search-payload-ready', api.handlePayload);
    }
    if (window.AiAssistantPremiumSearchSummary === api) {
        delete window.AiAssistantPremiumSearchSummary;
    }
});
</script>
