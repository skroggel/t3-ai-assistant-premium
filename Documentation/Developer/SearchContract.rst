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

Template mapping
    Maps engine-specific result fields into the common browser payload.

Premium JavaScript
    Sends the normalized visible result payload to the asynchronous AI summary
    request.

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
4. map rendered result rows to the common browser payload in the result
   template;
5. use the existing ``ai_assistant_premium.search_result_retriever`` processor
   in the summary profile.

Do not add a second backend search execution solely for the AI summary. The
browser payload is specifically intended to reuse what the visitor already
sees.
