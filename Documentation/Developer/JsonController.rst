Generic JSON controller
=======================

The Premium JSON API is a transport adapter for complete Assistant turns. It
does not implement a second pipeline. The controller creates an
``AssistantRequest`` and delegates execution to the Core ``Orchestrator``.

Architecture
------------

..  code-block:: text

    Native search middleware       JsonController
             |                            |
             +-------- AssistantRequest +
                                      |
                              Orchestrator->handle()
                                      |
                              AssistantResponse

The native middleware returns a redirect. The JSON controller returns the
Assistant response as JSON. Both use the same Assistant profile and pipeline.

Controller action
-----------------

The action is registered as ``JsonController->assistantAction`` and exposed by
the dedicated page type ``1790831372``. Its request parameters are namespaced by
the Premium Search plugin:

..  code-block:: text

    tx_aiassistantpremium_search[query]
    tx_aiassistantpremium_search[assistantProfile]
    tx_aiassistantpremium_search[chatIdentifier]
    tx_aiassistantpremium_search[settingsJson]
    tx_aiassistantpremium_search[token]
    tx_aiassistantpremium_search[userLanguage]

The controller does not accept search result rows. The Summary flow uses the
existing Assistant SSE chat instead. Its canonical payload is placed in
``settings.search.capturedResults`` by the Vue payload-builder component before
the chat stream starts.

Assistant response
------------------

The JSON response follows the Core ``AssistantResponse`` shape and only adds
the request identity used by the frontend:

..  code-block:: json

    {
        "answer": "",
        "context": {
            "currentQuery": "Optimized Query",
            "answerContext": "",
            "retrievalCount": 0,
            "retrievals": [],
            "sources": []
        },
        "debug": [],
        "assistantProfile": 1001,
        "chatIdentifier": "search-123"
    }

For a query-optimization profile without an Answer Generator, ``answer`` may
be empty. The optimized query is then available at:

..  code-block:: javascript

    response.context.currentQuery

Security
--------

The controller is not an open Assistant runner. Before executing the
Orchestrator it verifies:

* the Premium license;
* the Assistant profile against the active site's
  ``aiAssistantPremium.allowedAssistantProfiles`` setting;
* the signed frontend token;
* the site, Assistant profile and chat identifier bound into that token;

The token is created by ``FrontendRequestTokenService`` and rendered into the
Premium custom element. It is signed with TYPO3's encryption key and contains
an expiration timestamp. The browser may see the token; its purpose is
integrity and capability validation, not secrecy.

Extending the controller
------------------------

New JSON operations should reuse the existing controller boundary and Core
Orchestrator. Do not create a second pipeline in Premium. If an operation needs
additional input, validate it before building the ``AssistantRequest`` and keep
the response compatible with ``AssistantResponse``.
