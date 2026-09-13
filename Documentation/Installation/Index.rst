..  _premium-installation:

============
Installation
============

Prerequisites
=============

Before installing Premium, install and configure the base AI Assistant
extension. In particular, the site needs the AI/vector-store connections and
assistant/indexer infrastructure required by the premium feature you want to
use.

Composer installation
=====================

Install the package in the TYPO3 project root:

..  code-block:: bash

    composer require madj2k/t3-ai-assistant-premium

The package automatically installs its required Composer dependencies,
including the base AI Assistant package and ``smalot/pdfparser``.

After Composer installation:

1. run the TYPO3 database schema update;
2. clear TYPO3 caches;
3. configure the premium license key;
4. include or activate the supplied site set / TypoScript where frontend search
   integration is used;
5. configure the desired premium feature.

Database schema
===============

The Shopware integration stores indexing state in
``tx_aiassistant_indexer_shopware_state``. Apply the normal TYPO3 schema update
after installation and after extension upgrades.

Site set and TypoScript
=======================

The extension provides the site set:

..  code-block:: text

    madj2k/ai-assistant-premium

Its setup imports the Premium TypoScript configuration. Projects not using site
sets can include the static TypoScript template **AI Assistant Premium**.

License setup
=============

Premium features are not usable without a valid license. Continue with
:ref:`premium-license`.

Render this documentation
=========================

From the extension root, render the manual with the official TYPO3 Guides
container:

..  code-block:: bash

    docker run --rm --pull always \
      -v "$(pwd):/project" \
      -it ghcr.io/typo3-documentation/render-guides:latest \
      --config=Documentation

The renderer creates HTML output using the TYPO3 documentation theme. The
``Documentation/guides.xml`` file contains the rendering configuration.
