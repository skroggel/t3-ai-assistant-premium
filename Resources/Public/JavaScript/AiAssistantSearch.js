/**
 * Streams an AI summary after an engine-neutral search payload is available.
 */
class AiAssistantSearch extends AiAssistantChatBox {
    /**
     * @type {Object}
     */
    static defaults = AiAssistantChatBox.mergeOptions(AiAssistantChatBox.defaults, {
        selectors: {
            container: '.js-aiassistant-summary-container',
            form: '.js-aiassistant-summary-form',
            input: '.js-aiassistant-summary-query',
            resultPayload: '.js-aiassistant-search-results',
            settingsInput: 'input[name$="[settingsJson]"]',
            chatIdentifierInput: 'input[name$="[chatIdentifier]"]',
        },
        datasetKeys: {
            autoSubmit: 'autoSubmit',
        },
    });

    /**
     * @param {HTMLFormElement} form
     * @param {Object} options
     */
    constructor(form, options = {}) {
        super(form, AiAssistantChatBox.mergeOptions(AiAssistantSearch.defaults, options || {}));

        this.resultsAttached = this.attachCapturedResults();
        if (this.resultsAttached && this.container instanceof HTMLElement) {
            this.container.hidden = false;
            const shell = this.container.closest('.js-aiassistant-summary-shell');
            if (shell instanceof HTMLElement) {
                shell.hidden = false;
            }
        }
        this.autoSubmitIfRequested();
    }

    /**
     * Adds matching first-page search results to the summary runtime settings.
     *
     * @return {boolean}
     */
    attachCapturedResults() {
        const settingsInput = this.form?.querySelector(this.options.selectors.settingsInput);
        const chatIdentifierInput = this.form?.querySelector(this.options.selectors.chatIdentifierInput);
        if (!(settingsInput instanceof HTMLInputElement) || !(chatIdentifierInput instanceof HTMLInputElement)) {
            return false;
        }

        const chatIdentifier = chatIdentifierInput.value.trim();
        for (const payloadElement of document.querySelectorAll(this.options.selectors.resultPayload)) {
            if (
                !(payloadElement instanceof HTMLScriptElement)
                || (payloadElement.dataset.chatIdentifier || '').trim() !== chatIdentifier
            ) {
                continue;
            }

            try {
                const capturedResults = JSON.parse(payloadElement.textContent || '');
                const runtimeSettings = JSON.parse(settingsInput.value || '{}');
                if (
                    !capturedResults
                    || typeof capturedResults !== 'object'
                    || Array.isArray(capturedResults)
                    || Number(capturedResults.page || 1) !== 1
                    || !Array.isArray(capturedResults.results)
                    || capturedResults.results.length === 0
                ) {
                    return false;
                }

                const normalizedSettings = runtimeSettings
                    && typeof runtimeSettings === 'object'
                    && !Array.isArray(runtimeSettings)
                    ? runtimeSettings
                    : {};
                normalizedSettings.search = normalizedSettings.search
                    && typeof normalizedSettings.search === 'object'
                    && !Array.isArray(normalizedSettings.search)
                    ? normalizedSettings.search
                    : {};
                normalizedSettings.search.capturedResults = capturedResults;
                settingsInput.value = JSON.stringify(normalizedSettings);

                return true;
            } catch (error) {
                return false;
            }
        }

        return false;
    }

    /**
     * Submits the summary request after its result payload was attached.
     */
    autoSubmitIfRequested() {
        if (!this.form || !this.input || !this.resultsAttached) {
            return;
        }

        const autoSubmit = this.form.dataset.autoSubmit || '1';
        if (autoSubmit === '0' || autoSubmit === 'false' || (this.input.value || '').trim() === '') {
            return;
        }

        window.requestAnimationFrame(() => {
            if (typeof this.form.requestSubmit === 'function') {
                this.form.requestSubmit();
                return;
            }

            this.form.dispatchEvent(new Event('submit', {
                bubbles: true,
                cancelable: true,
            }));
        });
    }

    /**
     * @param {ParentNode} root
     * @param {Object} options
     * @return {Array<AiAssistantSearch>}
     */
    static init(root = document, options = {}) {
        const mergedOptions = AiAssistantChatBox.mergeOptions(AiAssistantSearch.defaults, options || {});
        const instances = [];
        root.querySelectorAll(mergedOptions.selectors.form).forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.aiAssistantPremiumSummaryInitialized === '1') {
                return;
            }

            form.dataset.aiAssistantPremiumSummaryInitialized = '1';
            instances.push(new AiAssistantSearch(form, mergedOptions));
        });

        return instances;
    }
}
