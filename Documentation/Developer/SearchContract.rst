..  _premium-search-contract:

======================
Search result contract
======================

The Premium search-result retriever is engine-neutral. It expects the browser
integration to submit a normalized payload made from the result rows that were
already rendered by the search extension.

Responsibilities
================

Search engine integration
    Owns the actual search request, filters, pagination and result rendering.

Vue payload builder
    Maps engine-specific result fields into the common browser payload through
    the ``ai-assistant-search-payload-builder`` custom element.

Premium JavaScript
    Publishes the normalized payload through the
    ``ai-assistant-search-payload-ready`` event. The Summary custom element adds
    its runtime context and starts the existing SSE chat.

``SearchResultRetrieverProcessor``
    Converts normalized entries into retrieval documents for the assistant
    pipeline. It does not query ``ke_search``, Solr or another search backend.

Adding another search engine
============================

To integrate another engine:

1. keep the native search form and results;
2. configure the native form selector;
3. extend the integration registry if the engine uses a different query
   parameter;
4. render the payload-builder custom element with the result rows and field
    mapping;
5. use the existing ``ai_assistant_premium.search_result_retriever`` processor
   in the summary profile.

Do not add a second backend search execution solely for the AI summary. The
browser payload is specifically intended to reuse what the visitor already
sees.
