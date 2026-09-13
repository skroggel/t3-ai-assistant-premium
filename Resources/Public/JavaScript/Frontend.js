document.addEventListener('DOMContentLoaded', () => {
  if (typeof AiAssistantSearch !== 'undefined') {
    AiAssistantSearch.init(document, window.aiAssistantSearchOptions || {});
  }
});
