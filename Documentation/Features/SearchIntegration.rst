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
