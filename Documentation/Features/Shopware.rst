..  _premium-shopware:

=================
Shopware indexing
=================

Premium adds a Shopware connector and indexer to the generic indexing
infrastructure of the base extension. Products are fetched from Shopware,
converted into index documents and written through the standard AI Assistant
storage/vector pipeline.

Create an indexer
=================

Create an indexer configuration in the AI Assistant backend and select the
Shopware indexer. The Premium extension adds a Shopware configuration palette to
``tx_aiassistant_indexer``.

Required connection fields
--------------------------

``Shopware base URL``
    Base URL of the Shopware installation / API endpoint.

``Client ID``
    Integration client identifier used for Shopware API authentication.

``API key``
    Secret associated with the configured Shopware integration.

``Verify TLS``
    Keep enabled for production. Disable only for controlled development
    environments with a deliberately untrusted certificate.

Indexing fields
---------------

``Lookback days``
    Defines the fallback time range for incremental product retrieval.

``Custom fields``
    Comma-separated/custom-field configuration used when building product
    documents.

``Indexed fields``
    Defines the Shopware product fields that are included in the indexed
    representation. The default includes translated name, translated
    description and product number.

Download/link fields
--------------------

``Download base URL`` and ``Download path`` control the public/source URL
construction used by the product document factory where a separate public shop
URL is required.

Smoke test
==========

Before running a complete index, test the configured connection:

..  code-block:: bash

    vendor/bin/typo3 aiassistant:shopware:test --indexer=123

Use ``--json`` for machine-readable output. See :ref:`command-shopware-test`.

Incremental indexing
====================

Run:

..  code-block:: bash

    vendor/bin/typo3 aiassistant:shopware:index --indexer=123

The command uses the registered identifier ``aiassistant.indexer.shopware``.
Cursor information is stored in the Premium Shopware state record so subsequent
runs can continue from the last known position.

Deleted products
================

Removing an item in Shopware does not automatically send an event to TYPO3.
Use the cleanup command to compare locally indexed Shopware sources against the
remote API and remove documents for products that no longer exist:

..  code-block:: bash

    vendor/bin/typo3 aiassistant:shopware:cleanup-deleted --indexer=123

For larger catalogs, tune ``--limit`` and ``--batch-size`` and run the command
repeatedly/scheduled.

License behavior
================

Shopware indexing and connector operations require a valid Premium license and
can fail with ``LicenseRequiredException`` when no valid license is available.
