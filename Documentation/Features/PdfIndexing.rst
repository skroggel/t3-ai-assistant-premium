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

Layout-aware reading order
==========================

For every page, the adapter compares the native PDF text stream with the
positioned text elements. A geometry-based analysis detects plain text,
parallel text columns, tables and pages that mix columns with structured rows.

The analysis uses coordinates, recurring column starts and whitespace only. It
does not depend on document language, heading names or customer-specific
keywords. Text columns are emitted column by column. Table-like regions retain
their visual row associations and use `` | `` as a cell separator. Common PDF
ligatures are normalized before indexing.

Each page document includes diagnostic metadata:

* ``pdf_extraction_strategy``: ``native``, ``positioned`` or
  ``positioned-fallback``
* ``pdf_layout_type``: ``plain``, ``columns``, ``table``, ``mixed`` or
  ``empty``
* ``pdf_column_count`` and ``pdf_table_row_count``
* ``pdf_extraction_confidence``

This metadata is diagnostic. It does not change chunking, retrieval or the
assistant pipeline outside PDF extraction.

Backend diagnostics
===================

The Premium extension adds an independent **AiPremium** backend module next to
the AI Assistant module. Its first tab, **PDF diagnostics**, accepts a PDF for
a read-only preview of the extraction result. The module shows the selected
strategy, detected layout, column and table-row counts, confidence and
normalized text for every page. For the first 20 pages it also renders an
in-memory page preview. Coordinate regions on the preview are linked to their
matching segments in the normalized extraction text for visual inspection.
Different text objects occupying nearly identical coordinates are reported as
potentially hidden or overprinted text. This warning is diagnostic only; the
text is not removed from the index automatically.

Diagnostic uploads are parsed from their temporary upload location. They are
not stored, indexed or written to a vector collection. The upload limit is 25
MB and the module requires a valid Premium license.

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

PDF coordinates describe visual placement, not semantics. Highly irregular
magazine layouts, text drawn as paths, incorrectly encoded fonts and complex
overlapping elements can therefore still require document-specific review.

License behavior
================

The PDF adapter participates only while the Premium license is valid. See
:ref:`premium-license`.
