..  _premium-pdf-indexing:

============
PDF indexing
============

Premium registers ``Madj2k\\AiAssistantPremium\\Indexing\\Adapter\\PdfAdapter``
with the base indexing system using the ``aiassistant.indexing.adapter`` service
tag.

Text extraction
===============

PDF files are parsed with ``smalot/pdfparser``. No external ``pdftotext``
binary or other system package is required.

The adapter normalizes extracted text before it is passed into the regular AI
Assistant indexing pipeline. The remaining chunking, embeddings, vector-store
storage and source tracking are provided by the base extension.

Matched-page links
==================

By default, retrieval results point to the PDF file itself. In the central AI
Assistant backend **Configuration** module, the Premium PDF registry
contributor adds the option to link directly to the page that contained the
matching content.

After changing this setting, force a full re-index of the affected PDFs so
stored metadata is rebuilt. For example:

..  code-block:: bash

    vendor/bin/typo3 aiassistant:index:files \
      --indexer=123 --reset-cursor --force

Replace ``123`` with the UID of the file indexer.

Limitations
===========

The parser extracts text embedded in the PDF. Scanned image-only PDFs require
OCR before they can provide meaningful text to the indexer. Password-protected,
damaged or otherwise unreadable documents can also fail extraction.

License behavior
================

The PDF adapter participates only while the Premium license is valid. See
:ref:`premium-license`.
