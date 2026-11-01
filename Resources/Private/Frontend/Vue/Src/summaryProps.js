/**
 * @typedef {Object} AiAssistantSearchSummaryProps
 * @property {string} sseEndpoint AI Assistant SSE endpoint for follow-up messages.
 * @property {string} jsonEndpoint JSON endpoint for complete responses.
 * @property {string} integration Search integration identifier.
 * @property {string} query Original search query used for the summary.
 * @property {string|number} assistantProfile Assistant profile uid.
 * @property {string} chatIdentifier Stable search/chat identifier.
 * @property {string} requestToken Signed frontend request token.
 * @property {string|number} startTimestamp Session reset timestamp.
 * @property {string} settingsJson Serialized runtime settings.
 * @property {string} chatOptionsJson Serialized normalized frontend chat options.
 * @property {string} labelsJson Serialized translated labels for the nested chat.
 * @property {string} uiComponentsJson Serialized UI component definitions for the nested chat.
 * @property {string} errorHandling Frontend error handling mode.
 * @property {boolean|string|number} showWithoutResults Whether to run the summary without search results.
 * @property {boolean|string|number} streamResponse Whether to stream the assistant response.
 * @property {Object|Function|string|null} chatComponent Optional Vue component replacing the standard chat.
 */

/** Shared prop contract for the standard Summary component and its custom element. */
export const summaryProps = {
    // Request identity and search context.
    sseEndpoint: { type: String, default: '' },
    jsonEndpoint: { type: String, default: '' },
    integration: { type: String, default: 'ke_search' },
    query: { type: String, default: '' },
    assistantProfile: { type: [String, Number], default: 0 },
    chatIdentifier: { type: String, default: '' },
    requestToken: { type: String, default: '' },
    startTimestamp: { type: [String, Number], default: 0 },

    // Runtime settings passed to the nested assistant chat.
    settingsJson: { type: String, default: '{}' },
    chatOptionsJson: { type: String, default: '{}' },
    labelsJson: { type: String, default: '{}' },
    uiComponentsJson: { type: String, default: '[]' },
    errorHandling: { type: String, default: 'default' },
    showWithoutResults: { type: [String, Number, Boolean], default: false },
    streamResponse: { type: [String, Number, Boolean], default: true },

    // Optional Vue component replacing the standard chat presentation.
    chatComponent: { type: [Object, Function, String], default: null },
    // Optional normalized payload supplied directly by a parent Vue search app.
    capturedResults: { type: Object, default: null },
};
