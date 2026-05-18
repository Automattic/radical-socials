=== Radical Socials ===
Contributors:      automattic
Tags:              fediverse, activitypub, mastodon, rss, following
Requires at least: 6.5
Tested up to:      6.9
Requires PHP:      8.0
Stable tag:        0.1.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Escape walled gardens with a self-hosted WordPress site that feels like home: import your archive, follow people across the Fediverse and RSS.

== Description ==

Radical Socials turns a fresh WordPress site into a place that feels like the social network you came from, without locking you back inside one.

* Import your social-media export (Instagram, Twitter/X, Bluesky, TikTok) into your own site as WordPress posts you fully own.
* Hide the standard WordPress chrome behind a one-click "advanced mode" toggle, so the site feels like an app — not a CMS.
* Follow people across the Fediverse (ActivityPub via the **ActivityPub** plugin), via RSS / Atom (with WebSub push when supported), and via WP.com Reader.
* A unified Following feed pulls everything onto one timeline.
* A Favorites list lets you bookmark feed items permanently, even after they roll off the feed.
* Block-theme-friendly: a Social Menu block, an Author Avatar / Name block for feed items, and Following / Favorites links you can drop into any Navigation block.
* No extra database tables — everything stored as standard WordPress posts and meta, so it survives backups, exports, and WP-CLI.

This plugin is the product. The default theme (Radical Theme) is an optional design layer; the plugin works on any block theme.

== Installation ==

1. Install and activate the [ActivityPub plugin](https://wordpress.org/plugins/activitypub/) — Radical Socials depends on it for following Fediverse accounts. (WordPress 6.5+ will prompt you and offer a one-click install.)
2. Install and activate Radical Socials.
3. Visit *Radical Socials* in the admin sidebar to set up your profile, connect feeds, and import an archive.

== Frequently Asked Questions ==

= Do I need a Mastodon account or my own ActivityPub server? =

No. The required ActivityPub plugin turns your WordPress site itself into a Fediverse identity. You can follow people from Mastodon, Pixelfed, PeerTube, etc., without joining any other server.

= Why does it require the ActivityPub plugin? =

All Fediverse follow / unfollow / inbox handling is delegated to the ActivityPub plugin so we don't reinvent the protocol layer. WordPress 6.5+ uses the `Requires Plugins` header to enforce this — Radical Socials will not activate without ActivityPub installed and active.

= Does it support feeds without ActivityPub? =

Yes. RSS / Atom feeds (with WebSub push when the publisher supports it) and WordPress.com Reader subscriptions work alongside ActivityPub follows on the same timeline.

= Where does my data go? =

It stays on your WordPress site. There's no Radical Socials cloud service. The plugin contacts the third-party services listed below — and only those — only when you take an explicit action.

= Will I lose my data if I deactivate the plugin? =

Your imported posts, follows, and favorites are stored as standard WordPress posts and meta, so they remain in the database after deactivation. Cron schedules and WebSub subscriptions are cleaned up on deactivation.

== External services ==

Radical Socials connects to third-party services only when you ask it to. Every outbound call is initiated by an explicit user action (clicking Follow, adding an RSS URL, connecting a WP.com account, importing an archive, etc.); the plugin makes no background telemetry or analytics calls.

= ActivityPub / Fediverse servers =

When you follow someone on the Fediverse, Radical Socials uses the ActivityPub plugin to perform a WebFinger lookup against the remote host and then exchange ActivityPub follow / Accept activities with it. Additionally, the plugin polls each followed actor's outbox URL periodically so that posts from that account appear in your timeline.

What is sent: the remote actor's identifier (e.g. `@user@example.social`) and standard ActivityPub follow / Accept payloads signed by your WordPress site's actor key. No personal data beyond what the ActivityPub plugin already advertises about your blog actor.
Service: each remote Fediverse instance you choose to follow.
Terms vary per instance.

= WordPress.com / WP.com Reader =

When you click "Connect WordPress.com" in the settings page, Radical Socials starts an OAuth 2 authorization-code flow against `public-api.wordpress.com`. After you authorize the connection on WordPress.com, the plugin receives an access token and uses it on every subsequent Reader request to read your subscriptions and recent items.

What is sent: an authorization request that names your site as the OAuth client, then the access token on each subsequent Reader call.
Service: WordPress.com.
Terms of service: https://wordpress.com/tos/.
Privacy policy: https://automattic.com/privacy/.

= WP.com OAuth broker (radicalsocials.wpcomstaging.com) =

WordPress.com's OAuth requires each app's redirect URI to be pre-registered, which would force every plugin install to register its own WP.com app. To avoid that friction, Radical Socials by default routes the OAuth handshake through a small broker hosted at https://radicalsocials.wpcomstaging.com/. The broker holds the WP.com app credentials, brokers the authorize redirect, and swaps the authorization code for an access token. The token is returned to your site over HTTPS and stored only on your site — the broker does not log, persist, or have access to tokens or your WordPress.com data after the swap.

What is sent: at handshake start, a random opaque state token and your site's callback URL. After authorization, the WordPress.com authorization code is exchanged via the broker once and then discarded.
Service: Radical Socials project broker (operated by the plugin authors, hosted on WordPress.com Atomic).

To opt out: define your own `RS_WPCOM_CLIENT_ID` and `RS_WPCOM_CLIENT_SECRET` in wp-config.php (after registering a WordPress.com app at https://developer.wordpress.com/apps/). The plugin then talks to WordPress.com directly without touching the broker. To use a different broker entirely, define `RS_WPCOM_PROXY_URL` with its base URL.

= Arbitrary RSS / Atom feed URLs =

When you add an RSS feed by URL (or via OPML import), Radical Socials fetches that URL using WordPress's HTTP API and parses it with the bundled SimplePie library to discover items and the WebSub hub. The same URLs are then re-fetched on a 15-minute schedule.

What is sent: an HTTP `GET` from your server with WordPress's default user agent. URLs are validated to refuse local / private-network targets before fetching.
Service: whichever publisher hosts the feed.

= WebSub hubs =

For RSS feeds that advertise a WebSub hub, Radical Socials sends a subscribe request to that hub so new posts are pushed to your site instead of polled. Subscriptions are renewed before they expire.

What is sent: a subscription request with a callback URL pointing at your site's REST endpoint, plus a randomly-generated secret used to verify signed push payloads.
Service: whichever WebSub hub the publisher uses (commonly Google's `pubsubhubbub.appspot.com` or `superfeedr.com`).

= Mastodon "import follows from account" =

If you use the "Import follows from a Mastodon account" feature in settings, Radical Socials fetches that account's public Following collection from the source instance and queues a follow for each entry.

What is sent: HTTP `GET` requests to the source Mastodon instance to read the public Following list.
Service: the Mastodon instance you specify.

== Privacy ==

Radical Socials does not collect, store, or transmit usage analytics, telemetry, or any other data to first-party services. All outbound HTTP traffic is to the user-initiated third-party services listed above.

== Changelog ==

= 0.1.0 =
* Initial public release.

== Source code ==

Radical Socials is developed in the open at https://github.com/Automattic/radical-socials. The unminified source for every script shipped in `build/` lives alongside it in `src/`.
