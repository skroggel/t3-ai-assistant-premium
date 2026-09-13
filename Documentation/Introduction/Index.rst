..  _premium-introduction:

============
Introduction
============

AI Assistant Premium is an add-on for the TYPO3 extension
``madj2k/t3-ai-assistant``. It does not replace the base extension. Instead it
registers additional adapters, indexers, connectors, pipeline processors,
frontend integrations and backend configuration fields through the extension
points provided by AI Assistant.

Features
========

The premium package currently provides:

* PDF text extraction and PDF-aware indexing;
* optional links to the matching PDF page;
* a Shopware product connector and indexer;
* incremental Shopware indexing with stored cursor state;
* cleanup of products deleted from Shopware;
* a Shopware connection smoke test command;
* AI query optimization in front of an existing site search;
* an engine-neutral browser result payload for AI summaries;
* supplied integration templates for ``ke_search``;
* an engine-neutral retrieval processor for already rendered search results;
* simple remote license validation.

Relationship to the base extension
==================================

The base extension supplies the core TYPO3 integration, including assistant
profiles, pipeline configuration, AI/vector-store connections, indexer
configuration, source persistence, diagnostics and the standard frontend chat.
Premium adds specialized data sources and integrations on top.

For general setup, assistant profile configuration and the core indexing model,
refer to the `AI Assistant repository
<https://github.com/skroggel/t3-ai-assistant>`__.

Runtime dependencies
====================

The Composer package declares the following relevant requirements:

* TYPO3 Core, Extbase and Frontend ``^13.4``;
* PHP ``^8.4``;
* ``madj2k/t3-ai-assistant`` ``^2.0``;
* ``madj2k/ai-core`` ``^2.0``;
* ``smalot/pdfparser`` ``^2.12``.

``tpwd/ke_search`` is suggested but not required. Premium search processing is
engine-neutral; the extension ships ready-to-use template integration for
``ke_search`` and exposes a ``solr`` integration identifier for projects that
provide the corresponding Solr template mapping.
