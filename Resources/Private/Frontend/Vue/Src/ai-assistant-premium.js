import { defineCustomElement } from 'vue';
import AiAssistantSearchEnhancer from './components/AiAssistantSearchEnhancer.ce.vue';
import AiAssistantSearchJsonEnhancer from './components/AiAssistantSearchJsonEnhancer.ce.vue';
import AiAssistantSearchPayloadBuilder from './components/AiAssistantSearchPayloadBuilder.ce.vue';
import AiAssistantSearchSummary from './components/AiAssistantSearchSummary.ce.vue';


customElements.define(
    'ai-assistant-search-payload-builder',
    defineCustomElement(AiAssistantSearchPayloadBuilder, { shadowRoot: false }),
);

customElements.define(
    'ai-assistant-search-enhancer',
    defineCustomElement(AiAssistantSearchEnhancer, { shadowRoot: false }),
);

customElements.define(
    'ai-assistant-search-enhancer-json',
    defineCustomElement(AiAssistantSearchJsonEnhancer, { shadowRoot: false }),
);

customElements.define(
    'ai-assistant-search-summary',
    defineCustomElement(AiAssistantSearchSummary, { shadowRoot: false }),
);
