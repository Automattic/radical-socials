# Radical Socials

A WordPress plugin to help people escape closed-wall social media platforms. Import your archive from Instagram, TikTok, Twitter, or Bluesky and get a self-hosted site that feels like the social network you came from.

## Getting started (local development)

Requires [Docker Desktop](https://www.docker.com/products/docker-desktop/) and Node 20 (`nvm use`).

```bash
npm install
npm run env:start
```

Site: `http://localhost:8890` — Admin: `http://localhost:8890/wp-admin` (`admin` / `password`)

```bash
npm run env:stop     # stop
npm run env:logs     # debug logs
npm run env:run -- post list   # run WP-CLI commands
npm run env:clean    # wipe DB and start fresh
```
