..  _premium-architecture:

============
Architecture
============

Service registration
====================

Premium services are autowired through ``Configuration/Services.yaml``. The
main extension-point registrations are:

``PdfAdapter``
    Tag: ``aiassistant.indexing.adapter``

``ShopwareConnector``
    Tag: ``aiassistant.indexing.connector``

``ShopwareIndexer``
    Tag: ``aiassistant.indexing.indexer``

``SearchResultRetrieverProcessor``
    Tag: ``aiassistant.assistant.pipeline.processor``

``PdfRegistryFieldContributor``
    Tag: ``aiassistant.backend.configuration.registry_field_contributor``

This keeps Premium additive: the base extension discovers the Premium
implementations through registries rather than hard-coded dependencies on the
Premium namespace.

License boundary
================

``Madj2k\\AiAssistantPremium\\License\\LicenseService`` centralizes license
lookup, hashing, the HTTP request and process-local memoization. Premium feature
entry points depend on this service instead of duplicating license URL logic.

The public contract consists primarily of:

``isValid()``
    Returns whether a valid premium license is available in the current PHP
    request/process.

``requireValidLicense()``
    Throws ``LicenseRequiredException`` when no license is available.

Shopware flow
=============

The Shopware index command builds a normal ``IndexingRequest`` and delegates to
the base ``IndexingCommandRunner`` with the identifier
``aiassistant.indexer.shopware``. The premium indexer then resolves its
configuration, reads products through ``ShopwareConnector``, builds documents
and delegates persistence/vector storage to the base indexing framework.

Search flow
===========

The search integration deliberately separates the server-side query rewrite
from the browser-side result transfer. The native search engine remains the
system of record for filtering, ranking and rendering.
