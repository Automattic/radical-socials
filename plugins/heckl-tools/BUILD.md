# Build

The release zip includes the unminified JavaScript and CSS source in `src/`.
The compiled files in `build/` can be regenerated from this plugin directory:

```bash
npm install
npm run build
```

The generated block-template fallbacks in `templates/` are included as plain
HTML. They are produced from the Heckl block theme during the project release
process and are reviewed as shipped in the plugin.
