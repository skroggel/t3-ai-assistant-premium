..  _premium-configuration:

=============
Configuration
=============

This chapter groups settings by source. See :ref:`premium-reference` for a
compact reference.

Extension Configuration
=======================

``licenseKey``
    Premium license key. Prefer ``AI_ASSISTANT_PREMIUM_LICENSE`` in deployments
    where environment-based secret management is available. See
    :ref:`premium-license`.

TypoScript
==========

The extension configures template, partial and layout root paths under:

..  code-block:: typoscript

    plugin.tx_aiassistantpremium

The defaults can be overridden through constants for project-specific
frontend templates.

Search defaults
---------------

The default search configuration is:

..  code-block:: typoscript

    plugin.tx_aiassistantpremium.settings.search {
        integration = ke_search
        formSelector = #form_kesearch_pi1
    }

The ``ke_search`` plugin receives Premium template root paths at a high numeric
priority so the shipped integration templates can participate in rendering.

Site set
========

The site set ``madj2k/ai-assistant-premium`` imports the Premium setup and is the
recommended configuration entry point for projects using TYPO3 site sets.

FlexForms
=========

The search enhancement plugin exposes:

``settings.search.integration``
    Search engine integration identifier. The current selection contains
    ``ke_search`` and ``solr``.

``settings.search.formSelector``
    CSS selector for the existing native search form.

``settings.assistantProfile``
    Optional assistant profile used for query optimization.

The search-summary plugin selects an assistant profile used to process the
browser result payload.

AI Assistant backend configuration
==================================

The Premium PDF registry field contributor extends the base extension's central
configuration registry with PDF-specific options such as linking to the matched
page.
