..  _premium-troubleshooting:

===============
Troubleshooting
===============

Premium feature is inactive
===========================

Check the license first:

* is ``licenseKey`` configured, or is ``AI_ASSISTANT_PREMIUM_LICENSE`` set?
* can the TYPO3 host reach ``https://license.mediafiles.de`` over HTTPS?
* does the SHA-256 filename for the configured key exist on the license server?
* does that URL return HTTP ``200``?

Remember that the validation result is cached only for the current PHP
request/process. Correcting the server-side file takes effect on a subsequent
request / process.

Shopware command fails immediately
==================================

A missing/invalid license can cause a ``LicenseRequiredException``. If the
license is valid, run the smoke test with the same indexer UID:

..  code-block:: bash

    vendor/bin/typo3 aiassistant:shopware:test --indexer=123

Then verify base URL, client ID, API key and TLS settings.

Shopware does not update products
=================================

Try a run with ``--reset-cursor`` and inspect the command output. Also verify
the configured lookback period and indexed field list. Use ``--json`` in
scheduled environments to capture structured diagnostics.

Deleted Shopware products remain searchable
===========================================

The normal incremental index command does not necessarily discover deleted
remote entities. Schedule ``aiassistant:shopware:cleanup-deleted`` separately.

PDF contains no useful text
===========================

Verify that the PDF contains selectable embedded text. Image-only scans require
OCR before ``smalot/pdfparser`` can provide text to the indexing adapter.

Search still works but AI optimization does not
================================================

This is an intentional fallback behavior. Verify:

* Premium license status;
* the configured form selector;
* that the optimizer assistant profile is selected;
* that the profile itself is usable in the base extension;
* the engine's expected native query parameter;
* frontend JavaScript loading.

Search summary is empty
=======================

Ensure the summary assistant profile includes
``ai_assistant_premium.search_result_retriever`` and that the result template
creates the normalized browser payload. The retriever does not execute the
search backend itself.
