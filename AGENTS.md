# Heckl — Agent Context

## Local Development Environment

Uses `@wordpress/env` (wp-env) via Docker. Requires Docker Desktop running.

```bash
npm install          # install deps (first time only)
npm run env:start    # start WP at http://localhost:8890 (admin: http://localhost:8890/wp-admin)
npm run env:stop     # stop containers
npm run env:logs     # tail container logs
npm run env:run -- help   # run any WP-CLI command, e.g. npm run env:run -- post list
npm run env:clean    # wipe DB and uploads, keep containers
npm run env:destroy  # remove containers and volumes entirely
```

Default credentials: `admin` / `password`

Debug is on by default (`WP_DEBUG`, `WP_DEBUG_LOG`, `SCRIPT_DEBUG`). Logs write to `wp-content/debug.log` inside the container — visible via `npm run env:logs`.

Query Monitor plugin is pre-installed for inspecting queries, hooks, and HTTP requests.

To override settings locally (e.g. a different PHP version or extra plugins) create `.wp-env.override.json` — it is gitignored.

## What We're Building

A WordPress plugin that lets people escape walled-garden social media (Instagram, TikTok, Twitter, Bluesky) by importing their archive into a self-hosted WordPress site. The site should feel like the social network they came from — not like WordPress.

**Target user:** Someone who has never used WordPress. The import is the onboarding. Everything should feel like product onboarding, not CMS setup.

## Core Principles

- **Hide WordPress complexity by default.** Standard WP admin (Posts, Pages, Appearance, Plugins) is hidden. An "advanced mode" toggle restores it for power users.
- **Modular from the start.** Built as one plugin with clean internal module boundaries so pieces can be extracted later if needed.
- **No extra database tables.** Use standard WP post/meta so the data works with WP CLI, backup plugins, and search without modification.
- **The import runs once** (or can be re-run for a newer export). No ongoing API sync in v1.

## Plugin vs Theme Boundary

**The plugin is the product. The theme is a swappable design layer.**

Plugin functionality must work on any installed WordPress theme. The `heckl` we're building is the default design layer — an FSE block theme styled to feel like a social network — but it is not required for the plugin to function. Any extra frontend behaviour the plugin adds (custom bar, feed, navigation) must be theme-agnostic.

## Rough Architecture (subject to change)

The plugin will likely be organized into modules:

- **importers** — one per platform (Instagram, Bluesky, Twitter, TikTok). Each implements a shared interface: parse source → normalized posts → import to WP.
- **post-types** — registers custom post types and taxonomies for social content. Each platform may get its own CPT so they can have different metadata and different admin UI per platform.
- **admin** — a React app registered as a WP admin page, communicating via the REST API. Three core screens: feed/grid view, new post composer, settings.
- **custom-bar** — a fixed navigation bar rendered in `wp_footer`. Provides home/explore/create/profile navigation and frontend post creation. May become a block eventually, but starts as a PHP module.
- **feed** — pulls from social media APIs and surfaces content in the frontend. Uses native core blocks (primarily `core/query`) rather than custom blocks.
- **client-side navigation** — makes page transitions seamless using the WordPress Interactivity API router. Implemented via `render_block` filters that inject router region attributes onto existing core block output.

`heckl` is a standalone block theme (not a "companion"). It adapts its visual style to the platform the user migrated from (photo grid for Instagram, text feed for Twitter/Bluesky, vertical video for TikTok).

## Supported Platforms (v1 targets)

- **Instagram** — ZIP upload from Meta's "Download Your Information"
- **Bluesky** — handle or CAR file via AT Protocol (no auth needed)
- **Twitter/X** — ZIP upload from Settings archive
- **TikTok** — ZIP upload from "Download Your Data"

## Data Model (rough)

Each social post stores: platform, original ID, original date, content/caption, media, tags, original URL. Profile data (display name, bio, avatar) is imported and pre-fills the WP user profile.

Taxonomies to preserve: hashtags (social-tag), location tags (social-location).

## What's Out of Scope for v1

- Multi-source aggregation ("build my site from my online portfolio")
- Ongoing API sync
- Social connection / ActivityPub integration / Reader / FYP algorithm
- Auto-generated sites
