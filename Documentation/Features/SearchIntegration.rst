..  _premium-search-integration:

=========================
AI-enhanced site search
=========================

The Premium search integration enhances an existing site search; it is not a
replacement search engine. The native search extension remains responsible for
its form, filters, query execution, result list and pagination.

The integration is split into two independent phases:

1. optional AI query optimization before the native search executes;
2. optional AI processing / summarization of the result rows already rendered
   by the native search.

This design avoids executing the underlying search engine a second time.

Query optimization
==================

``AiAssistantSearchEnhancer.js`` adds integration metadata to an existing native
search form. ``SearchQueryMiddleware`` reads the engine-specific native query
parameter and can optimize the query with an assistant profile.

If no optimizer profile is configured, the original search term is forwarded
unchanged.

The default ``ke_search`` form selector is:

..  code-block:: css

    #form_kesearch_pi1

``ke_search`` continues to submit its native
``tx_kesearch_pi1[sword]`` parameter and all filters.

For a Solr integration, the middleware expects the native ``tx_solr[q]`` query
parameter. Projects must provide the result-template mapping for Solr.

Vue and JSON search clients
---------------------------

Non-native clients do not need to use ``AiAssistantSearchEnhancer.js``. They can
send the same control parameters directly with the regular search request:

..  code-block:: text

    tx_aiassistantpremium_search[integration]=ke_search
    tx_aiassistantpremium_search[optimizerProfile]=123
    tx_aiassistantpremium_search[chatIdentifier]=search-abc
    tx_aiassistantpremium_search[response]=json

With ``response=json`` the middleware does not redirect. It returns:

..  code-block:: json

    {
        "originalQuery": "original search term",
        "effectiveQuery": "optimized search term",
        "optimized": true,
        "integration": "ke_search",
        "chatIdentifier": "search-abc",
        "state": "..."
    }

The Vue client can use ``effectiveQuery`` for the subsequent native ke_search
JSON request. Without ``response=json`` the existing native form/redirect flow
remains unchanged.

Result normalization for Vue
----------------------------

Vue clients can reuse the server-side result normalization through the Premium
``SearchController::normalizeAction`` action. Send the rows and their field
mapping as JSON form parameters:

..  code-block:: javascript

    const formData = new FormData();
    formData.set('payload', JSON.stringify(resultRows));
    formData.set('fields', JSON.stringify({
        id: 'number',
        title: 'title_text',
        text: 'teaser',
        url: 'url',
        type: 'type',
        score: 'score'
    }));
    formData.set('total', String(total));
    formData.set('page', '1');
    formData.set('integration', 'ke_search');
    formData.set('chatIdentifier', chatIdentifier);
    formData.set('originalQuery', originalQuery);
    formData.set('effectiveQuery', effectiveQuery);

    const response = await fetch(summaryNormalizeUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    const capturedResults = await response.json();

The returned ``capturedResults`` object can be assigned to
``settings.search.capturedResults`` before starting the normal AI Assistant
SSE chat request. The same ``SearchResultPayloadBuilder`` is used by the Fluid
ViewHelper and this JSON action, so both integrations produce identical data.

Search result payload
=====================

The browser integration serializes visible result rows into a standardized JSON
payload. ``SearchResultRetrieverProcessor`` converts this payload into
retrieval documents for the assistant pipeline.

The processor service identifier is:

..  code-block:: text

    ai_assistant_premium.search_result_retriever

Its processor type is ``retriever`` and it is registered through the
``aiassistant.assistant.pipeline.processor`` service tag.

Pipeline setup
==============

For a search summary assistant profile, add the premium search-result retriever
as a retriever step. It can be combined with a normal Qdrant retriever to merge
website search results with additional indexed knowledge.

For clarity, use descriptive step titles such as ``Search results`` and
``Additional knowledge``. The sources then remain distinguishable in the prompt
and can use independent chunk/character limits.

``ke_search`` integration
=========================

Premium ships overridden / extended ``ke_search`` templates under
``Resources/Private/KeSearch`` and adds those paths through TypoScript.

Add the normal ``ke_search`` searchbox to the page and configure the Premium
search enhancement content element / plugin with:

* integration ``ke_search``;
* the native form selector;
* an optional optimizer assistant profile.

For AI result summaries, configure the corresponding summary content element
with the assistant profile whose pipeline contains the premium search-result
retriever.

Graceful fallback
=================

Without JavaScript, the unchanged native search form continues to function.
Without an optimizer profile, the native query is not modified. Without a valid
Premium license, the premium middleware/processing is bypassed rather than
replacing the site's ordinary search behavior.
