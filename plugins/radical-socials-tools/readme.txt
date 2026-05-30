=== Radical Socials Tools ===
Contributors:      automattic, mikachan, onemaggie
Tags:              fediverse, activitypub, mastodon, rss, feed-reader
Requires at least: 6.7
Tested up to:      7.0
Requires PHP:      8.0
Stable tag:        1.0.1
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Own your social media: follow people across the Fediverse, WordPress.com Reader subscriptions, and RSS — all on one timeline.

== Description ==

Radical Socials Tools turns a fresh WordPress site into a place that feels like the social network you came from, without locking you back inside one.

* Follow people across the Fediverse (Mastodon, Pixelfed, PeerTube, …) via the optional **ActivityPub** plugin, via RSS / Atom feeds (with WebSub push when the publisher supports it), and via WordPress.com Reader subscriptions.
* A unified Following timeline that merges every source into one stream — with ActivityPub posts rendered as social-style cards (avatar, display name, handle, relative time, "Reposted by …") so they don't look like blog entries dropped into a feed.
* A Favorites list so you can bookmark items permanently — they survive after the live feed rolls them off.
* A lightweight Custom Bar that replaces the WordPress admin bar on the front end with Home / Create / Profile shortcuts — the site feels like an app, not a CMS.
* Frontend post composer (a small inline editor) that publishes via the REST API.
* OPML import / export for moving in and out of other feed readers, plus a one-shot "import follows from a Mastodon account" tool.
* Block-theme-friendly: ships a Social Menu block, Following / Favorites link blocks for any Navigation block, and plugin-default block templates so the Following and Favorites archives have a working layout on any active theme.
* No extra database tables — everything lives as standard WordPress posts and meta, so it survives backups, exports, and WP-CLI.

== Installation ==

1. Install and activate Radical Socials Tools.
2. (Optional, recommended) Install and activate the [ActivityPub plugin](https://wordpress.org/plugins/activitypub/) so you can follow Fediverse accounts and let your own posts federate.
3. Visit *Radical Socials Tools* in the admin sidebar to set up your profile and connect feeds.
4. Install the Radical Socials theme (optional, recommended) for a better experience.

== Frequently Asked Questions ==

= Do I need a Mastodon account or my own ActivityPub server? =

No. With the optional ActivityPub plugin installed, your WordPress site itself becomes a Fediverse identity. You can follow people from Mastodon, Pixelfed, PeerTube, etc., without joining any other server.

= Is the ActivityPub plugin required? =

No, it's optional. Radical Socials Tools works without it — you can still follow RSS feeds and WordPress.com Reader subscriptions, import archives, manage favorites, and use the unified timeline. Without ActivityPub, the only thing you lose is Fediverse follows (and the ability to federate your own posts outward). If you later install the ActivityPub plugin, Fediverse functionality lights up automatically.

= Does it support feeds without ActivityPub? =

Yes. RSS / Atom feeds (with WebSub push when the publisher supports it) and WordPress.com Reader subscriptions work alongside ActivityPub follows on the same timeline.

= Where does my data go? =

It stays on your WordPress site. There's no Radical Socials Tools cloud service. The plugin contacts the third-party services listed below — and only those — only when you take an explicit action.

= Will I lose my data if I deactivate the plugin? =

Your feed items, follows, and favorites are stored as standard WordPress posts and meta, so they remain in the database after deactivation. Cron schedules and WebSub subscriptions are cleaned up on deactivation.

= What about when I uninstall the plugin entirely? =

By default, uninstall preserves your feed items, favorites, and follow lists — Radical Socials Tools is often used as a personal archive of content you've collected, so silently wiping that on uninstall would be a data-loss surprise. Only plugin-internal flags (cron coordination, transients, OAuth tokens) are removed.

If you'd rather have a clean wipe on uninstall, turn on *Settings → Following → "Delete all data when the plugin is uninstalled"* before removing the plugin. With that toggle on, uninstall also removes the feed items, follow records, favorites, custom taxonomies, and all plugin options.

= Will activating the plugin change my permalink settings? =

Only if you're still on the WordPress default ("Plain"). Pretty permalinks are required for the ActivityPub WebFinger endpoint and for our own `/following/` and `/favorites/` URLs to resolve, so on activation we set the permalink structure to `/%postname%/` when none is set. Any non-default permalink structure you've already chosen is left alone.

== External services ==

Radical Socials Tools connects to third-party services only when you ask it to. Every outbound call is initiated by an explicit user action (clicking Follow, adding an RSS URL, connecting a WP.com account, importing an archive, etc.); the plugin makes no background telemetry or analytics calls.

= ActivityPub / Fediverse servers =

When you follow someone on the Fediverse, Radical Socials Tools uses the ActivityPub plugin to perform a WebFinger lookup against the remote host and then exchange ActivityPub follow / Accept activities with it. After the follow is established, Radical Socials Tools polls each followed actor's outbox URL periodically (every 15 minutes, chunked) so new posts from that account appear in your timeline. When an actor's display name, avatar URL, or outbox URL is unknown or stale, Radical Socials Tools also issues a one-shot `GET` against that actor's profile JSON URL to refresh those fields.

What is sent: the remote actor's identifier (e.g. `@user@example.social`) and standard ActivityPub follow / Accept payloads signed by your WordPress site's actor key. No personal data beyond what the ActivityPub plugin already advertises about your blog actor.
Service: each remote Fediverse instance you choose to follow.
Terms vary per instance.

= WordPress.com / WP.com Reader =

When you click "Connect WordPress.com" in the settings page, Radical Socials Tools starts an OAuth 2 authorization-code flow against `public-api.wordpress.com`. After you authorize the connection on WordPress.com, the plugin receives an access token and uses it on every subsequent Reader request to read your subscriptions and recent items.

What is sent: an authorization request that names your site as the OAuth client, then the access token on each subsequent Reader call.
Service: WordPress.com.
Terms of service: https://wordpress.com/tos/.
Privacy policy: https://automattic.com/privacy/.

= WP.com OAuth broker (radicalsocials.wpcomstaging.com) =

WordPress.com's OAuth requires each app's redirect URI to be pre-registered, which would force every plugin install to register its own WP.com app. To avoid that friction, Radical Socials Tools by default routes the OAuth handshake through a small broker hosted at https://radicalsocials.wpcomstaging.com/. The broker holds the WP.com app credentials, brokers the authorize redirect, and swaps the authorization code for an access token. The token is returned to your site over HTTPS and stored only on your site.

The broker code is open-source in this repository and does not log or persist tokens — they are exchanged once and discarded. The broker itself is hosted on WordPress.com Atomic and follows Automattic's standard server-log retention policy; standard HTTP access logs (timestamp, request URL, IP) apply to traffic in transit just as they would to any HTTPS endpoint.

What is sent: at handshake start, a random opaque state token and your site's callback URL. After authorization, the WordPress.com authorization code is exchanged via the broker once and then discarded.
Service: Radical Socials Tools project broker (operated by the plugin authors, hosted on WordPress.com Atomic).

To opt out entirely: define your own `RS_WPCOM_CLIENT_ID` and `RS_WPCOM_CLIENT_SECRET` in wp-config.php (after registering a WordPress.com app at https://developer.wordpress.com/apps/). The plugin then talks to WordPress.com directly without touching the broker. To use a different broker, define `RS_WPCOM_PROXY_URL` with its base URL. To run your own broker on a separate WordPress site, also define `RS_WPCOM_PROXY_MODE=true` along with the client credentials.

= Arbitrary RSS / Atom feed URLs =

When you add an RSS feed by URL (or via OPML import), Radical Socials Tools fetches that URL using WordPress's HTTP API and parses it with the bundled SimplePie library to discover items and the WebSub hub. The same URLs are then re-fetched on a 15-minute schedule (chunked to 10 feeds per tick so the schedule never overwhelms a shared host).

What is sent: an HTTP `GET` from your server with WordPress's default user agent. URLs are validated to refuse local / private-network targets before fetching.
Service: whichever publisher hosts the feed.

= WebSub hubs =

For RSS feeds that advertise a WebSub hub, Radical Socials Tools sends a subscribe request to that hub so new posts are pushed to your site instead of polled. Subscriptions are renewed before they expire.

What is sent: a subscription request with a callback URL pointing at your site's REST endpoint, plus a randomly-generated per-subscription secret. The hub uses this secret as the key in an HMAC-SHA1 signature it attaches to every push payload it sends back; Radical Socials Tools verifies that signature (with a constant-time comparison) before accepting the push.
Service: whichever WebSub hub the publisher uses (commonly Google's `pubsubhubbub.appspot.com` or `superfeedr.com`).

= Mastodon "import follows from account" =

If you use the "Import follows from a Mastodon account" feature in settings, Radical Socials Tools fetches that account's public Following collection from the source instance and queues a follow for each entry.

What is sent: HTTP `GET` requests to the source Mastodon instance to read the public Following list.
Service: the Mastodon instance you specify.

== Privacy ==

Radical Socials Tools does not collect, store, or transmit usage analytics, telemetry, or any other data to first-party services. All outbound HTTP traffic is to the user-initiated third-party services listed above.

== Screenshots ==

1. Unified Following timeline — ActivityPub, RSS, and WordPress.com Reader items merged into a single feed with social-style cards.
2. Frontend post composer — an inline editor that publishes through the REST API without leaving the front end.
3. Custom Bar — a lightweight Home / Create / Profile bar that replaces the WordPress admin bar for logged-in visitors.
4. Settings → Following — manage Fediverse follows, RSS subscriptions, WordPress.com Reader subscriptions, and OPML import / export from one screen.
5. Onboarding wizard — guided first-run flow with one-click ActivityPub install and add-your-first-feed prompts.
6. Favorites archive — a permanent bookmark list for items that have rolled off the live feed.

== Upgrade Notice ==

= 1.0.0 =
First stable release. No prior version to upgrade from.

== Changelog ==

= 1.0.0 =
* First stable release.
* Unified Following timeline merging RSS / Atom (with WebSub push when the publisher supports it), WordPress.com Reader subscriptions, and ActivityPub follows.
* ActivityPub posts render as self-contained social cards (avatar, display name, handle, relative time, optional "Reposted by …" line) — independent of the active theme.
* Favorites: bookmark feed items permanently as a separate custom post type so they survive feed rotation.
* Frontend post composer that publishes via the REST API.
* Custom Bar (collapsible vertical rail on desktop, fixed bottom strip on mobile) replacing the WordPress admin bar for logged-in visitors.
* OPML import / export plus a one-shot "import follows from a Mastodon account" tool.
* Plugin-default block templates for the Feed and Favorites archives so any active theme has a working layout out of the box; site editors can override either template per the standard WordPress flow.
* Following / Favorites navigation-link blocks hooked into core/navigation, plus a like-button hooked into core/post-template.
* Compact admin under *Radical Socials Tools* with Profile and Following tabs.
* Onboarding wizard with one-click ActivityPub plugin install and add-feed flows.

== Source code ==

Radical Socials Tools is developed in the open at https://github.com/Automattic/radical-socials. The unminified source for every script shipped in `build/` lives alongside it in `src/`.
