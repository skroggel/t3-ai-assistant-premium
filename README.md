# AI Assistant Premium

Premium integrations for `madj2k/ai-assistant`.

This extension is intentionally modular. Optional third-party integrations must not be hard dependencies.

The frontend integrations are delivered as compiled Vue custom elements. The
Premium Search and Search Summary plugins provide the signed frontend context;
Vue applications consume the page-level Premium clients for query optimization
and summaries. The generic JSON controller executes a complete Assistant turn
through the Core Orchestrator.

See the documentation for:

- [Search integration](Documentation/Features/SearchIntegration.rst)
- [Generic JSON controller](Documentation/Developer/JsonController.rst)

## License

Set the license key in **Admin Tools > Settings > Extension Configuration > AI Assistant Premium**.
For deployments, the environment variable `AI_ASSISTANT_PREMIUM_LICENSE` can be used instead and takes precedence.


## Render the documentation

Run from the extension root:

```bash
docker run --rm --pull always -v "$(pwd)":/project -it ghcr.io/typo3-documentation/render-guides:latest --config=Documentation
```

## Build the frontend bundles with DDEV

Run the Premium bundle build from the project root:

```bash
ddev exec sh -lc 'cd /var/www/html/vendor/madj2k/t3-ai-assistant-premium && npm run build'
```

The compiled bundle is included in the extension at:

```text
Resources/Public/JavaScript/ai-assistant-premium.js
```
