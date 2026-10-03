<template>
    <span hidden aria-hidden="true"></span>
</template>

<script setup>
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * @typedef {Object} AiAssistantSearchEnhancerProps
 * @property {string} integration Search integration identifier.
 * @property {string} formSelector CSS selector for the native search form.
 * @property {string|number} assistantProfile Assistant profile used for query optimization.
 * @property {string} chatIdentifier Stable chat identifier forwarded to the search middleware.
 */

/** @type {import('vue').DefineProps<AiAssistantSearchEnhancerProps>} */
const props = defineProps({
    integration: { type: String, default: '' },
    formSelector: { type: String, default: '' },
    assistantProfile: { type: [String, Number], default: 0 },
    chatIdentifier: { type: String, default: '' },
});

/** @type {string} Extbase parameter namespace used by the premium search middleware. */
const formParameterPrefix = 'tx_aiassistantpremium_search';

/** @type {MutationObserver|null} Observer used while the native form is not available. */
let observer = null;

/**
 * Resolves the configured native search form.
 *
 * @return {HTMLFormElement|null} Matching form or null for an invalid/missing selector.
 */
const resolveForm = () => {
    if (!props.formSelector.trim()) {
        return null;
    }

    try {
        const form = document.querySelector(props.formSelector);
        return form instanceof HTMLFormElement ? form : null;
    } catch (error) {
        return null;
    }
};

/**
 * Adds or updates one hidden premium control field in the native form.
 *
 * @param {HTMLFormElement} form Native search form.
 * @param {string} key Premium middleware control key.
 * @param {string|number} value Control value.
 * @return {void}
 */
const addControlField = (form, key, value) => {
    const normalizedValue = String(value ?? '').trim();
    if (!normalizedValue) {
        return;
    }

    const name = `${formParameterPrefix}[${key}]`;
    let input = Array.from(form.elements).find((element) => (
        element instanceof HTMLInputElement && element.name === name
    ));

    if (!(input instanceof HTMLInputElement)) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        form.append(input);
    }

    input.value = normalizedValue;
};

/**
 * Enhances the native search form with the premium middleware controls.
 *
 * @return {boolean} True when the form was found and enhanced.
 */
const enhanceForm = () => {
    const form = resolveForm();
    if (!form) {
        return false;
    }

    addControlField(form, 'integration', props.integration);
    addControlField(form, 'assistantProfile', props.assistantProfile);
    addControlField(form, 'chatIdentifier', props.chatIdentifier);
    observer?.disconnect();
    return true;
};

/**
 * Finds the form immediately or observes the document until it exists.
 *
 * @return {void}
 */
onMounted(() => {
    if (enhanceForm()) return;

    observer = new MutationObserver(() => enhanceForm());
    observer.observe(document.body, { childList: true, subtree: true });
});

/**
 * Disconnects the form observer when the custom element is removed.
 *
 * @return {void}
 */
onBeforeUnmount(() => observer?.disconnect());
</script>
