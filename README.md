# AI Assistant Premium

Premium integrations for `madj2k/ai-assistant`.

This extension is intentionally modular. Optional third-party integrations must not be hard dependencies.

## License

Set the license key in **Admin Tools > Settings > Extension Configuration > AI Assistant Premium**.
For deployments, the environment variable `AI_ASSISTANT_PREMIUM_LICENSE` can be used instead and takes precedence.

## Render the documentation

Run from the extension root:

```bash
docker run --rm --pull always -v "$(pwd)":/project -it ghcr.io/typo3-documentation/render-guides:latest --config=Documentation
```
