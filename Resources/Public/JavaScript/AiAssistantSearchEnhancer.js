/**
 * Adds optional AI metadata to an existing search-engine form.
 *
 * The search engine keeps ownership of the form, its query input and filters.
 * Without JavaScript the native form therefore continues to work unchanged.
 */
class AiAssistantSearchEnhancer {
    static controlParameter = 'tx_aiassistantpremium_search';

    /**
     * @param {HTMLElement} element
     */
    constructor(element) {
        this.element = element;
        this.form = this.resolveForm(element.dataset.formSelector || '');

        if (!(this.form instanceof HTMLFormElement)) {
            return;
        }

        this.addControlField('integration', element.dataset.integration || '');
        this.addControlField('optimizerProfile', element.dataset.optimizerProfile || '0');
        this.addControlField('chatIdentifier', element.dataset.chatIdentifier || '');
    }

    /**
     * Resolves the native search form without making assumptions about its engine.
     *
     * @param {string} selector
     * @return {HTMLFormElement|null}
     */
    resolveForm(selector) {
        if (selector.trim() === '') {
            return null;
        }

        try {
            const form = document.querySelector(selector);
            return form instanceof HTMLFormElement ? form : null;
        } catch (error) {
            return null;
        }
    }

    /**
     * Creates or updates one hidden control field in the native search form.
     *
     * @param {string} key
     * @param {string} value
     */
    addControlField(key, value) {
        if (value.trim() === '') {
            return;
        }

        const name = `${AiAssistantSearchEnhancer.controlParameter}[${key}]`;
        let input = Array.from(this.form.elements).find((element) => (
            element instanceof HTMLInputElement && element.name === name
        ));

        if (!(input instanceof HTMLInputElement)) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            this.form.append(input);
        }

        input.value = value;
    }

    /**
     * Enhances every declared native search form once.
     *
     * @param {ParentNode} root
     * @return {Array<AiAssistantSearchEnhancer>}
     */
    static init(root = document) {
        const instances = [];
        root.querySelectorAll('.js-aiassistant-search-enhancer').forEach((element) => {
            if (!(element instanceof HTMLElement) || element.dataset.aiAssistantInitialized === '1') {
                return;
            }

            element.dataset.aiAssistantInitialized = '1';
            instances.push(new AiAssistantSearchEnhancer(element));
        });

        return instances;
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        AiAssistantSearchEnhancer.init(document);
    });
} else {
    AiAssistantSearchEnhancer.init(document);
}
