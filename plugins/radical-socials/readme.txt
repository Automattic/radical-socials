=== Radical Socials ===
Contributors:      automattic, mikachan, onemaggie
Tags:              fediverse, activitypub, mastodon, rss, feed-reader
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

1. Install and activate Radical Socials.
2. (Optional, recommended) Install and activate the [ActivityPub plugin](https://wordpress.org/plugins/activitypub/) so you can follow Fediverse accounts and let your own posts federate.
3. Visit *Radical Socials* in the admin sidebar to set up your profile, connect feeds, and import an archive.

== Frequently Asked Questions ==

= Do I need a Mastodon account or my own ActivityPub server? =

No. With the optional ActivityPub plugin installed, your WordPress site itself becomes a Fediverse identity. You can follow people from Mastodon, Pixelfed, PeerTube, etc., without joining any other server.

= Is the ActivityPub plugin required? =

No, it's optional. Radical Socials works without it — you can still follow RSS feeds and WordPress.com Reader subscriptions, import archives, manage favorites, and use the unified timeline. Without ActivityPub, the only thing you lose is Fediverse follows (and the ability to federate your own posts outward). If you later install the ActivityPub plugin, Fediverse functionality lights up automatically.

= Does it support feeds without ActivityPub? =

Yes. RSS / Atom feeds (with WebSub push when the publisher supports it) and WordPress.com Reader subscriptions work alongside ActivityPub follows on the same timeline.

= Where does my data go? =

It stays on your WordPress site. There's no Radical Socials cloud service. The plugin contacts the third-party services listed below — and only those — only when you take an explicit action.

= Will I lose my data if I deactivate the plugin? =

Your imported posts, follows, and favorites are stored as standard WordPress posts and meta, so they remain in the database after deactivation. Cron schedules and WebSub subscriptions are cleaned up on deactivation.

= What about when I uninstall the plugin entirely? =

By default, uninstall preserves your imported posts, favorites, and follow lists — Radical Socials is often used as a personal archive of social-media content, so silently wiping that on uninstall would be a data-loss surprise. Only plugin-internal flags (cron coordination, transients, OAuth tokens) are removed.

If you'd rather have a clean wipe on uninstall, turn on *Settings → Following → "Delete all data when the plugin is uninstalled"* before removing the plugin. With that toggle on, uninstall also removes the imported feed items, follow records, favorites, custom taxonomies, and all plugin options.

= Will activating the plugin change my permalink settings? =

Only if you're still on the WordPress default ("Plain"). Pretty permalinks are required for the ActivityPub WebFinger endpoint and for our own `/following/` and `/favorites/` URLs to resolve, so on activation we set the permalink structure to `/%postname%/` when none is set. Any non-default permalink structure you've already chosen is left alone.

== External services ==

Radical Socials connects to third-party services only when you ask it to. Every outbound call is initiated by an explicit user action (clicking Follow, adding an RSS URL, connecting a WP.com account, importing an archive, etc.); the plugin makes no background telemetry or analytics calls.

= ActivityPub / Fediverse servers =

When you follow someone on the Fediverse, Radical Socials uses the ActivityPub plugin to perform a WebFinger lookup against the remote host and then exchange ActivityPub follow / Accept activities with it. After the follow is established, Radical Socials polls each followed actor's outbox URL periodically (every 15 minutes, chunked) so new posts from that account appear in your timeline. When an actor's display name, avatar URL, or outbox URL is unknown or stale, Radical Socials also issues a one-shot `GET` against that actor's profile JSON URL to refresh those fields.

The "Test feed(s)" buttons in *Settings → Diagnostics* perform the same `GET` requests against the URLs you've already added, on demand, when you click them.

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

WordPress.com's OAuth requires each app's redirect URI to be pre-registered, which would force every plugin install to register its own WP.com app. To avoid that friction, Radical Socials by default routes the OAuth handshake through a small broker hosted at https://radicalsocials.wpcomstaging.com/. The broker holds the WP.com app credentials, brokers the authorize redirect, and swaps the authorization code for an access token. The token is returned to your site over HTTPS and stored only on your site.

The broker code is open-source in this repository and does not log or persist tokens — they are exchanged once and discarded. The broker itself is hosted on WordPress.com Atomic and follows Automattic's standard server-log retention policy; standard HTTP access logs (timestamp, request URL, IP) apply to traffic in transit just as they would to any HTTPS endpoint.

What is sent: at handshake start, a random opaque state token and your site's callback URL. After authorization, the WordPress.com authorization code is exchanged via the broker once and then discarded.
Service: Radical Socials project broker (operated by the plugin authors, hosted on WordPress.com Atomic).

To opt out entirely: define your own `RS_WPCOM_CLIENT_ID` and `RS_WPCOM_CLIENT_SECRET` in wp-config.php (after registering a WordPress.com app at https://developer.wordpress.com/apps/). The plugin then talks to WordPress.com directly without touching the broker. To use a different broker, define `RS_WPCOM_PROXY_URL` with its base URL. To run your own broker on a separate WordPress site, also define `RS_WPCOM_PROXY_MODE=true` along with the client credentials.

= Arbitrary RSS / Atom feed URLs =

When you add an RSS feed by URL (or via OPML import), Radical Socials fetches that URL using WordPress's HTTP API and parses it with the bundled SimplePie library to discover items and the WebSub hub. The same URLs are then re-fetched on a 15-minute schedule (chunked to 10 feeds per tick so the schedule never overwhelms a shared host).

The "Test feed(s)" buttons in *Settings → Diagnostics* perform an immediate `GET` against the same URLs when you click them.

What is sent: an HTTP `GET` from your server with WordPress's default user agent. URLs are validated to refuse local / private-network targets before fetching.
Service: whichever publisher hosts the feed.

= WebSub hubs =

For RSS feeds that advertise a WebSub hub, Radical Socials sends a subscribe request to that hub so new posts are pushed to your site instead of polled. Subscriptions are renewed before they expire.

What is sent: a subscription request with a callback URL pointing at your site's REST endpoint, plus a randomly-generated per-subscription secret. The hub uses this secret as the key in an HMAC-SHA1 signature it attaches to every push payload it sends back; Radical Socials verifies that signature (with a constant-time comparison) before accepting the push.
Service: whichever WebSub hub the publisher uses (commonly Google's `pubsubhubbub.appspot.com` or `superfeedr.com`).

= Mastodon "import follows from account" =

If you use the "Import follows from a Mastodon account" feature in settings, Radical Socials fetches that account's public Following collection from the source instance and queues a follow for each entry.

What is sent: HTTP `GET` requests to the source Mastodon instance to read the public Following list.
Service: the Mastodon instance you specify.

== Privacy ==

Radical Socials does not collect, store, or transmit usage analytics, telemetry, or any other data to first-party services. All outbound HTTP traffic is to the user-initiated third-party services listed above.

== Upgrade Notice ==

= 0.1.0 =
First public release. No prior version to upgrade from.

== Changelog ==

= 0.1.0 =
* Initial public release.
* Unified Following timeline that merges RSS / Atom (with WebSub push), WordPress.com Reader, and ActivityPub follows.
* Importers for Instagram, Twitter / X, Bluesky, and TikTok archive exports — each entry stored as a standard WordPress post.
* Frontend post composer (Twitter-style inline editor) that publishes via the WordPress REST API.
* OPML import / export for moving in and out of other feed readers.
* Compact admin under *Settings → Radical Socials* with profile, following management, and a Diagnostics tab.
* Onboarding wizard with one-click ActivityPub plugin install and add-feed flows.

== Source code ==

Radical Socials is developed in the open at https://github.com/Automattic/radical-socials. The unminified source for every script shipped in `build/` lives alongside it in `src/`.
