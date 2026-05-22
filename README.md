# Radical Socials

A WordPress plugin that turns a fresh WordPress site into a personal social-feed reader: follow people across the Fediverse (via the [ActivityPub plugin](https://wordpress.org/plugins/activitypub/)), RSS / Atom feeds (with WebSub push), and WordPress.com Reader subscriptions — all on one unified Following timeline. Ships with a frontend post composer, a Favorites bookmark list, a lightweight in-app Custom Bar replacing the admin bar, and plugin-default block templates so the Following / Favorites archives have a working layout on any block theme.

This monorepo contains both the plugin and its companion block theme (`radical-theme`). The plugin is the product; the theme is an optional design layer.

User-facing docs live in [`plugins/radical-socials-tools/readme.txt`](./plugins/radical-socials-tools/readme.txt) and [`themes/radical-theme/readme.txt`](./themes/radical-theme/readme.txt).

## Getting started (local development)

Requires [Docker Desktop](https://www.docker.com/products/docker-desktop/) and Node 20 (`nvm use`).

```bash
npm install
npm run env:start
```

Site: `http://localhost:8890` — Admin: `http://localhost:8890/wp-admin` (`admin` / `password`)

```bash
npm run env:stop                       # stop containers
npm run env:logs                       # tail container logs (incl. debug.log)
npm run env:run -- post list           # any WP-CLI command
npm run env:clean                      # wipe DB + uploads, keep containers
npm run env:destroy                    # nuke containers + volumes
npm run test:integration               # run the in-WP integration suite
```

## Build pipeline

```bash
npm run build              # production webpack build for the plugin's src/
npm run start              # webpack dev watch
npm run build:templates    # flatten radical-theme templates into plugin-default
                           # block templates (output gitignored under
                           # plugins/radical-socials-tools/templates/)
npm run plugin-zip         # full release zip: build + flatten + stage + zip
npm run theme-zip          # theme release zip
```

`plugin-zip` always runs `build:templates` so the shipped archive carries fresh flattened versions of the theme's `archive-rs_feed_item` / `archive-rs_favorite` templates as plugin defaults. The theme is the source of truth — running the script regenerates the plugin's copies.

## Repo layout

```
plugins/radical-socials-tools/   The plugin (the product).
  modules/                 Self-contained feature modules.
    custom-bar/            Lightweight admin-bar replacement.
    following/             Feed CPTs, fetchers, REST, blocks, OPML, WebSub, OAuth.
    frontend-editor/       Inline frontend post composer.
    settings/              Admin settings page + onboarding wizard.
    templates/             Registers plugin-default block templates.
    dev/                   WP-CLI test harness (excluded from release zips).
  src/                     Webpack source for the admin / frontend JS bundles.
  templates/               Generated flattened templates (gitignored).
themes/radical-theme/      Companion block theme — the source of truth for
                           plugin-default templates.
bin/                       Release / build scripts.
```
