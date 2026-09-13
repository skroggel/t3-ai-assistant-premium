..  _premium-operations:

======================
Operational guidelines
======================

Scheduled Shopware indexing
===========================

Shopware indexing is designed to run in batches. Use a TYPO3 scheduler task or
an external cron job to call :ref:`command-shopware-index` regularly. The
indexer stores its continuation state so later runs can continue incrementally.

A typical cron invocation is:

..  code-block:: bash

    vendor/bin/typo3 aiassistant:shopware:index --indexer=123 --limit=100

Periodically run the deleted-product cleanup as well:

..  code-block:: bash

    vendor/bin/typo3 aiassistant:shopware:cleanup-deleted \
      --indexer=123 --limit=100 --batch-size=100

Monitoring
==========

For automated operation, use ``--json`` and evaluate the command exit code and
JSON result. The Shopware state table additionally tracks cursors and run
statistics used by the indexer.

After configuration changes
===========================

Changes to fields that affect the generated indexed document usually require a
forced or complete re-index to update existing vectors/documents. PDF link-mode
changes similarly require re-indexing the affected PDFs if the source metadata
stored in the vector database needs to change.
