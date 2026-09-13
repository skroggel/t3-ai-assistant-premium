# Documentation development

This directory contains the TYPO3-style reStructuredText documentation for
`madj2k/t3-ai-assistant-premium`.

Render it from the extension root with the official TYPO3 renderer:

```bash
docker run --rm --pull always \
  -v "$(pwd):/project" \
  -it ghcr.io/typo3-documentation/render-guides:latest \
  --config=Documentation
```

The renderer reads `Documentation/guides.xml` and creates the HTML manual using
the TYPO3 documentation theme.
