..  _premium-commands:

=================
Console commands
=================

..  _command-shopware-index:

``aiassistant:shopware:index``
=============================

Indexes Shopware content through the registered Shopware indexer.

Options:

``--indexer``
    Indexer configuration UID.

``--collection``
    Override the target vector collection.

``--cursor``
    Explicit batch cursor.

``--reset-cursor``
    Ignore stored cursor state and start from the beginning.

``--dry-run``
    Process without writing changes.

``--only-changed``
    Skip unchanged sources. The command defaults to changed-only behavior.

``--limit``
    Maximum sources processed in the batch. Default: ``100``.

``--json``
    Emit machine-readable JSON.

Example:

..  code-block:: bash

    vendor/bin/typo3 aiassistant:shopware:index \
      --indexer=123 --limit=100 --json

..  _command-shopware-cleanup:

``aiassistant:shopware:cleanup-deleted``
========================================

Checks locally indexed Shopware sources against Shopware and removes sources
whose remote products no longer exist.

Options:

``--indexer``
    Indexer configuration UID.

``--collection``
    Override the target vector collection.

``--cursor``
    Explicit cleanup cursor.

``--reset-cursor``
    Restart cleanup from the beginning.

``--dry-run``
    Report removals without writing them.

``--limit``
    Maximum indexed sources verified in the batch. Default: ``100``.

``--batch-size``
    Product IDs verified per Shopware API request. Default: ``100``.

``--json``
    Emit machine-readable JSON.

..  _command-shopware-test:

``aiassistant:shopware:test``
=============================

Tests a Shopware indexer configuration and previews product retrieval.

Options:

``--indexer``
    Shopware indexer configuration UID.

``--since``
    ISO timestamp used as the ``updatedAt`` lower bound in UTC.

``--days``
    Fallback time window in days. Default: ``1``.

``--limit``
    Preview up to N products, with a maximum of ``25``. Default: ``5``.

``--json``
    Emit machine-readable JSON.
