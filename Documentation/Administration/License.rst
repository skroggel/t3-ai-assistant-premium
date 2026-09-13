..  _premium-license:

=======
License
=======

AI Assistant Premium uses a deliberately simple remote license check. The
license key itself is configured in TYPO3 or through an environment variable.
The extension hashes the key locally and only requests the corresponding static
license file from the licensing server.

Configure the key
=================

The license key can be set in:

**Admin Tools > Settings > Extension Configuration > AI Assistant Premium**

The configuration key is ``licenseKey``.

For deployments and secret management, use the environment variable instead:

..  code-block:: bash

    AI_ASSISTANT_PREMIUM_LICENSE="YOUR-LICENSE-KEY"

The environment variable takes precedence over Extension Configuration.
Whitespace around the configured value is removed before hashing.

Validation algorithm
====================

The extension computes:

..  code-block:: text

    SHA256(trim(<license-key>))

It then requests:

..  code-block:: text

    https://license.mediafiles.de/t3-ai-assistant-premium/<HASH>.licence

A response with HTTP status ``200`` means the license is valid. A missing key,
another HTTP status, timeout or connection error is treated as an invalid
license.

The result is memoized for the lifetime of the current PHP request/process. It
is not persisted across PHP requests.

Network requirements
====================

The TYPO3 host needs outbound HTTPS access to ``license.mediafiles.de``. The
license check uses short connection/request timeouts, so an unavailable license
server does not hold a request indefinitely. An unavailable server nevertheless
means that the license is considered invalid for that PHP request.

Feature behavior without a license
==================================

Premium integrations fail closed:

* PDF support is not selected and extraction returns no premium PDF content;
* search optimization / premium search handling is bypassed;
* direct Shopware premium operations require a valid license and can throw a
  license exception.

This distinction keeps ordinary non-premium frontend handling functional while
preventing use of the premium feature itself.

License server administration
=============================

To determine the expected filename for a key on the license server:

..  code-block:: bash

    printf %s 'YOUR-LICENSE-KEY' | sha256sum

Create an empty ``<HASH>.licence`` file below
``t3-ai-assistant-premium/`` to activate that key. Remove the file to revoke it.
