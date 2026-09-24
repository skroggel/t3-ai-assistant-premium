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
positioned text elements. A geometry-based analysis first separates horizontal
page regions and then classifies each region as plain text, parallel columns or
a table. Full-width headings therefore remain above column regions, while
side-by-side tables can retain a different reading order from the prose above.
Short paired blocks on stable left and right anchors, such as captions in an
image grid, are retained as two-column blocks even when each individual band
contains too few lines to qualify as a prose column.
If parallel columns have different heights, continuation lines at the same
column anchor remain attached to the established column instead of becoming a
new full-width region when their estimated text width crosses the gutter.

The analysis uses coordinates, recurring column starts and whitespace only. It
does not depend on document language, heading names or customer-specific
keywords. Text columns are emitted column by column. Table-like regions retain
their visual row associations and use `` | `` as a cell separator. Common PDF
ligatures are normalized before indexing. Text objects with different font
sizes are grouped by their optical line centre rather than their raw baseline.
This preserves the left-to-right order of numbered labels whose large number
and smaller title are visually aligned but use different PDF baselines.

Before extracting individual pages, the adapter compares text objects in the
top and bottom page margins across the complete document. Repeated text at a
stable position and coherent page-number sequences are treated as headers or
footers and excluded from indexing. Position alone is not sufficient, so
one-off footnotes, cover dates and other unique margin content remain intact.

The page-level layout value summarizes these regional decisions. Each page
document includes diagnostic metadata:

* ``pdf_extraction_strategy``: ``native``, ``positioned``,
  ``positioned-aligned``, ``positioned-filtered`` or
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
Text-region widths use the PDF's embedded glyph metrics and horizontal text
scale where available, with a conservative average-width fallback for
incomplete fonts. Region heights use the vertical text-matrix scale and compact
ascender/descender bounds around the PDF baseline.
For consecutive styled text runs, the diagnostics reconstructs the PDF text
cursor advance when the parser reports an unchanged matrix origin. Explicitly
repositioned text remains at its original coordinates so genuine overprinting
is still visible.
The diagnostic view also displays the horizontal layout regions as a stacked
map and as numbered frames on the rendered page, including their full-width,
column or table classification. Confirmed headers and footers remain visible
in the layout map and are explicitly labelled as excluded from indexing.
The extracted-text preview can be switched between reconstructed text lines
and the original PDF text objects. Line view is the default for readable
inspection, while text-object view retains the atom-level parser diagnostics.
Layout blocks are displayed exclusively in the separate layout-analysis tab.
A third ``Qdrant-Chunks`` tab applies the selected file indexer's production
chunk settings with the shared text chunker and displays the exact
``payload.text`` values that will be embedded and stored per page. Hovering or
focusing a chunk highlights its intersecting text lines in the rendered PDF;
clicking locks that selection. Each card also shows its exact character range,
while overlapping boundary lines can consequently belong to adjacent chunks.
Adjacent rows with the same layout are grouped into coherent bands; significant
vertical gaps or transitions between full-width and multi-column content start
a new diagnostic region. Short parallel text blocks can also be recognized when
their baselines are offset, provided both blocks overlap vertically and remain
separated by a stable gutter.
Nested columns are analysed locally as well, for example when one side of an
outer two-column layout contains its own two-column grid of cards.
Different text objects occupying nearly identical coordinates are reported as
potentially hidden or overprinted text. This warning is diagnostic only; the
text is not removed from the index automatically.
Larger text-free regions containing substantial vector path artwork are also
shown as **Vektorgrafik** and explicitly labelled as excluded from indexing.
This conservative diagnostic hint explains content whose visible letters were
converted to outlines and therefore no longer exist as extractable PDF text;
it neither performs OCR nor changes the indexed text.

Diagnostic uploads are parsed from their temporary upload location. They are
not stored, indexed or written to a vector collection. The upload limit is 25
MB and the module requires a valid Premium license.
The displayed extracted text is produced by the same document extraction
service as ``PdfAdapter`` and is therefore the exact normalized adapter output
passed to the indexer before its configured chunking step. Diagnostics do not
maintain a second extraction implementation.

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
