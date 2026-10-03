..  _premium-vue-search:

Vue JSON search integration
===========================

This integration is intended for a Vue search application. The application
keeps ownership of search forms, filters, pagination and result rendering. The
Premium plugin provides the configuration and registers page-level clients.

Required plugins
----------------

Place the Premium **Search** plugin on the page. It renders:

..  code-block:: html

    <ai-assistant-search-enhancer-json
        integration="ke_search"
        assistant-profile="1001"
        chat-identifier="..."
        request-token="..."
        endpoint="..."
    ></ai-assistant-search-enhancer-json>

For summaries, also place the Premium **Search Summary** plugin. It renders the
Summary custom element and registers the Summary client.

The clients are available after the Premium bundle has mounted:

..  code-block:: javascript

    const optimizer = await window.AiAssistantPremiumSearch;
    const summary = await window.AiAssistantPremiumSearchSummary;

Query optimization
------------------

The Vue application supplies the current search query:

..  code-block:: javascript

    const response = await optimizer.optimize({
        searchQuery: currentSearchQuery
    });

The client adds the server-rendered Assistant context and calls the generic
Premium JSON controller. Use the optimized query from the Core response:

..  code-block:: javascript

    const effectiveQuery = response.context.currentQuery;

The optimizer response must be awaited before requesting filters or result rows.
Those two native search requests may run in parallel after optimization.

SearchApp example
-----------------

The following example describes the complete request flow of a Vue-based
``SearchApp``. The application remains responsible for the search form,
KeSearch requests, filters, pagination and result rendering. Premium only
optimizes the query and summarizes the returned result rows.

The Summary custom element is supplied by the separate Premium **Search
Summary** plugin. It is initially rendered by that plugin and moved into the
local slot between the search form and the result list when the first result
page is available:

..  code-block:: html

    <SearchForm @search="submitSearch" />
    <div ref="summaryMount" v-show="summaryVisible"></div>
    <SearchResult :result-rows="resultRows" />

Request lifecycle
~~~~~~~~~~~~~~~~~

The following is schematic code based on the current ``SearchApp``
implementation. The exact KeSearch namespace is integration-specific; the
example shows the KeSearch mapping used by the current integration.

..  code-block:: javascript

    const submitSearch = (query) => {
        searchWord.value = query;
        currentPage.value = 1;
        activeFilter.value = null;
        loadSearch();
    };

    const setPage = (page) => {
        currentPage.value = page;
        loadSearch();
    };

    const setFilter = (filter) => {
        activeFilter.value = filter;
        currentPage.value = 1;
        loadSearch();
    };

    const loadSearch = async ({ skipOptimization = false } = {}) => {
        let effectiveQuery = skipOptimization
            ? internalSearchWord
            : searchWord.value;

        // Optional Premium query optimization.
        if (!skipOptimization && searchWord.value.length > 2) {
            const optimizer = await getOptimizer();
            if (optimizer) {
                const response = await optimizer.optimize({
                    searchQuery: searchWord.value
                });
                effectiveQuery = response.context.currentQuery;
            }
        }

        internalSearchWord = effectiveQuery;

        // Load the actual result list and the available filters.
        const [results, filtersResponse] = await Promise.all([
            requestKeSearch({
                'tx_kesearch_pi1[headless_ce]': resultId,
                'tx_kesearch_pi1[sword]': effectiveQuery,
                'tx_kesearch_pi1[page]': currentPage.value,
                'tx_kesearch_pi1[sortByField]': sortByField.value,
                'tx_kesearch_pi1[sortByDir]': sortByDir.value,
                ...getActiveFilterParameter(),
                no_cache: '1'
            }),
            requestKeSearchFilters(effectiveQuery)
        ]);

        resultRows.value = results.resultrows;
        pageBrowser.value = results.pagebrowser;
        numberOfResults.value = results.numberofresults;
        filters.value = filtersResponse.filters;
        requestEnd.value = true;

        // 4. Wait until v-if has rendered the summary slot, then publish
        //    the first result page through the payload builder.
        await startSummary();
    };

    const initializeSearch = async () => {
        if (searchWord.value.length > 2) {
            const optimizer = await getOptimizer();
            if (optimizer) {
                const response = await optimizer.optimize({
                    searchQuery: searchWord.value
                });
                internalSearchWord = response.context.currentQuery;
            }
        }

        // The configuration request is made once when SearchApp starts.
        const configuration = await requestKeSearch({
            'tx_kesearch_pi1[headless_ce]': pluginId,
            'tx_kesearch_pi1[sword]': internalSearchWord,
            'tx_kesearch_pi1[sortByField]': sortByField.value,
            'tx_kesearch_pi1[sortByDir]': sortByDir.value,
            no_cache: '1'
        });

        placeholder.value = configuration.searchwordDefault;
        sortByField.value = configuration.sortByField;
        sortByDir.value = configuration.sortByDir;

        // The initial query was already optimized above, if applicable.
        await loadSearch({ skipOptimization: searchWord.value.length > 2 });
    };

The two KeSearch request types have different purposes:

``headless_ce = pluginId``
    Loads the KeSearch plugin configuration used by the Vue application, for
    example the default placeholder and sort settings.

``headless_ce = resultId``
    Loads the actual result list. The response contains ``resultrows``,
    ``pagebrowser``, ``numberofresults``, ``filters`` and the
    ``wordsTooShort`` state.

The filter request uses the configured KeSearch plugin ID and the current
effective query. Pagination and filter changes repeat the native KeSearch
result request and the filter request. In the current ``SearchApp`` example,
those actions also call the optimizer again for queries longer than two
characters. An implementation that keeps the effective query in local state
can pass ``skipOptimization: true`` for pagination and filter changes.

The actual request URLs are built from ``formAction``. A KeSearch request is
therefore conceptually equivalent to:

..  code-block:: text

    GET /search-page
        ?tx_kesearch_pi1[headless_ce]=<resultId>
        &tx_kesearch_pi1[sword]=<effectiveQuery>
        &tx_kesearch_pi1[page]=1
        &tx_kesearch_pi1[sortByField]=<field>
        &tx_kesearch_pi1[sortByDir]=<direction>
        &no_cache=1

The parallel filter request uses the configuration/plugin ID rather than the
result-content-element ID and does not request a page:

..  code-block:: text

    GET /search-page
        ?tx_kesearch_pi1[headless_ce]=<pluginId>
        &tx_kesearch_pi1[sword]=<effectiveQuery>
        &tx_kesearch_pi1[sortByField]=<field>
        &tx_kesearch_pi1[sortByDir]=<direction>

Premium query optimization
~~~~~~~~~~~~~~~~~~~~~~~~~~

``getOptimizer()`` resolves the JSON enhancer registered by the Premium
Search plugin. The Vue application passes only the query string; it does not
extract or construct KeSearch parameter names for the Premium request:

..  code-block:: javascript

    import { nextTick } from 'vue';

    const getOptimizer = () => {
        if (window.AiAssistantPremiumSearch?.optimize) {
            return Promise.resolve(window.AiAssistantPremiumSearch);
        }

        return new Promise((resolve) => {
            const eventName = 'ai-assistant-premium-search-ready';
            const onReady = () => {
                window.removeEventListener(eventName, onReady);
                resolve(window.AiAssistantPremiumSearch || null);
            };

            window.addEventListener(eventName, onReady, { once: true });
        });
    };

The optimizer then sends one ``POST`` request to the Premium JSON endpoint.
The JSON enhancer creates the following logical form fields from the
server-rendered element properties:

..  code-block:: text

    tx_aiassistantpremium_search[query]=<searchQuery>
    tx_aiassistantpremium_search[assistantProfile]=<configured profile>
    tx_aiassistantpremium_search[chatIdentifier]=<configured identifier>
    tx_aiassistantpremium_search[settingsJson]=<runtime settings>
    tx_aiassistantpremium_search[token]=<signed token>

The Vue application does not construct these fields itself and does not need
to know that KeSearch stores its query in
``tx_kesearch_pi1[sword]``. The ``SearchIntegrationRegistry`` is relevant to
the server-side native URL middleware, not to this direct JSON call.

..  code-block:: javascript

    const getSearchPayloadBuilder = () => {
        if (window.AiAssistantPremiumSearchPayloadBuilder?.publish) {
            return Promise.resolve(window.AiAssistantPremiumSearchPayloadBuilder);
        }

        return new Promise((resolve) => {
            const eventName = 'ai-assistant-premium-search-payload-builder-ready';
            const onReady = () => {
                window.removeEventListener(eventName, onReady);
                resolve(window.AiAssistantPremiumSearchPayloadBuilder || null);
            };

            window.addEventListener(eventName, onReady, { once: true });
        });
    };

    const startSummary = async () => {
        if (currentPage.value !== 1 || resultRows.value.length === 0) {
            return;
        }

        // requestEnd changes the DOM through v-if; wait until the slot exists.
        await nextTick();

        const summaryElement = document.querySelector(
            'ai-assistant-search-summary'
        );
        if (!(summaryElement instanceof HTMLElement)) {
            return;
        }

        summaryMount.value.replaceChildren(summaryElement);

        const builder = await getSearchPayloadBuilder();
        if (!builder?.publish) {
            return;
        }

        builder.publish({
            rows: resultRows.value,
            fields: resultFieldMapping,
            total: filtersCount.value.all || 0,
            context: {
                originalQuery: searchWord.value,
                effectiveQuery: internalSearchWord
            }
        });
        summaryVisible.value = true;
    };

Summary
-------

The Vue application supplies raw result rows to the shared payload builder. It
does not manually construct TYPO3 parameter prefixes, Assistant IDs or tokens:

..  code-block:: javascript

    const fields = {
            id: 'orig_uid',
            title: 'title_text',
            text: 'content_text',
            url: 'url',
            type: 'type',
            pageId: 'orig_pid',
            changedAt: 'date_timestamp',
            score: 'score'
    };

    builder.publish({
        rows: resultRows,
        fields,
        total: resultCount,
        context: { originalQuery: searchWord, effectiveQuery }
    });

The builder emits ``ai-assistant-search-payload-ready``. The Summary custom
element consumes the event, adds integration/chat/query context and puts the
canonical structure into ``settings.search.capturedResults``. The nested
``ai-assistant-chat`` then starts the existing AI Assistant SSE request with
the summary query. No separate summary request is needed.

The streamed chat request contains the runtime settings, including the
canonical search payload:

..  code-block:: text

    tx_aiassistant_chat[query]=<effectiveQuery>
    tx_aiassistant_chat[assistantProfile]=<configured profile>
    tx_aiassistant_chat[chatIdentifier]=<configured identifier>
    tx_aiassistant_chat[startTimestamp]=<session timestamp>
    tx_aiassistant_chat[settingsJson]=<runtime settings including search.capturedResults>

Security
--------

For query optimization, the Premium plugin renders a signed, expiring token.
The token is bound to the site, Assistant profile and chat identifier. The JSON
controller checks:

* Premium license validity;
* the site allowlist ``aiAssistantPremium.allowedAssistantProfiles``;
* token signature, expiry and context;
* the configured Assistant profile and token for the optimizer request.

The Summary SSE request reuses the canonical payload prepared by the Vue
builder through ``settings.search.capturedResults``.

The browser must never choose an arbitrary Assistant profile or pipeline. It
only uses the capability supplied by the Premium plugin.
