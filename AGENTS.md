# Radical Socials — Agent Context

## What We're Building

A WordPress plugin that lets people escape walled-garden social media (Instagram, TikTok, Twitter, Bluesky) by importing their archive into a self-hosted WordPress site. The site should feel like the social network they came from — not like WordPress.

**Target user:** Someone who has never used WordPress. The import is the onboarding. Everything should feel like product onboarding, not CMS setup.

## Core Principles

- **Hide WordPress complexity by default.** Standard WP admin (Posts, Pages, Appearance, Plugins) is hidden. An "advanced mode" toggle restores it for power users.
- **Modular from the start.** Built as one plugin with clean internal module boundaries so pieces can be extracted later if needed.
- **No extra database tables.** Use standard WP post/meta so the data works with WP CLI, backup plugins, and search without modification.
- **The import runs once** (or can be re-run for a newer export). No ongoing API sync in v1.

## Rough Architecture (subject to change)

The plugin will likely be organized into modules:

- **importers** — one per platform (Instagram, Bluesky, Twitter, TikTok). Each implements a shared interface: parse source → normalized posts → import to WP.
- **post-types** — registers custom post types and taxonomies for social content. Each platform may get its own CPT so they can have different metadata and different admin UI per platform.
- **admin** — a React app registered as a WP admin page, communicating via the REST API. Three core screens: feed/grid view, new post composer, settings.
- **theme-companion** — block patterns, template parts, theme.json overrides.

There will also be a companion block theme (FSE) that adapts its visual style to the platform the user migrated from (photo grid for Instagram, text feed for Twitter/Bluesky, vertical video for TikTok).

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
