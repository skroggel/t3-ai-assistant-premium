..  _premium-classical-search:

Classical search integration
============================

This integration keeps the native search engine in control of its form,
filters, pagination and result rendering. The Premium extension adds Vue custom
elements, so no separate Premium legacy JavaScript implementation is required.

Required page elements
----------------------

Place these plugins on the same page:

1. The native ``ke_search`` or Solr search plugin.
2. The Premium **Search** plugin.
3. The Premium **Search Summary** plugin when an AI Summary is wanted.

The Premium plugins are required even though the search form is native. They
provide the Assistant profile, chat identifier and signed request token used by
the compiled Vue bundle.

The classic template integration is enabled by default through:

..  code-block:: yaml

    aiAssistantPremium:
      useClassicalSearch: true

Set this value to ``false`` when the result list is rendered by a JavaScript /
Vue application instead. In that mode the Premium KeSearch Fluid overrides are
not loaded.

Search plugin configuration
---------------------------

The Search FlexForm contains:

``settings.search.integration``
    Search integration identifier, for example ``ke_search`` or ``solr``.

``settings.search.formSelector``
    Selector for the native search form, for example:

    ..  code-block:: css

        #form_kesearch_pi1

``settings.assistantProfile``
    Assistant profile used for query optimization.

The Vue custom element ``ai-assistant-search-enhancer`` observes this form and
adds the Premium control fields. The middleware then runs the complete
Assistant turn and redirects the native search to the effective query.

The browser does not receive an unrestricted profile selector. The profile must
also be listed in the active site configuration:

..  code-block:: yaml

    aiAssistantPremium:
      allowedAssistantProfiles:
        - 1001
      requestTokenTtl: 7200

Result payload
--------------

For a Summary, the result rendering integration must provide the visible result
rows to the Vue Summary. The normalized payload has this shape:

..  code-block:: json

    {
        "version": 1,
        "total": 12,
        "results": []
    }

Result rows are normalized in the Vue custom element
``ai-assistant-search-payload-builder``. The field mapping is supplied by the
integration template. The element publishes the payload through the
``ai-assistant-search-payload-ready`` event. The Summary custom element adds
integration, chat and query context before starting the Assistant chat.

Summary behavior
----------------

The Summary custom element listens for
``ai-assistant-search-payload-ready``. Once the payload is available, it adds
its own integration/chat/query context and starts ``ai-assistant-chat``. The
summary response is streamed through the existing Assistant SSE endpoint.

The Summary is normally started only for the first result page. This decision is
made by the result rendering integration, not by the payload builder.

Fallback behavior
-----------------

If JavaScript is disabled, the native search remains usable. If the Premium
license, token or Assistant profile is invalid, the native search continues with
the original query.
