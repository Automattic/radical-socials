=== Heckl Tools ===
Contributors:      automattic, mikachan, onemaggie
Tags:              fediverse, activitypub, mastodon, rss, feed-reader
Requires at least: 6.7
Tested up to:      7.0
Requires PHP:      8.0
Stable tag:        1.0.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Own your social media: follow people across the Fediverse, WordPress.com Reader subscriptions, and RSS — all on one timeline.

== Description ==

Heckl Tools turns a fresh WordPress site into a place that feels like the social network you came from, without locking you back inside one.

* Follow people across the Fediverse (Mastodon, Pixelfed, PeerTube, …) via the optional **ActivityPub** plugin, via RSS / Atom feeds (with WebSub push when the publisher supports it), and via WordPress.com Reader subscriptions.
* A unified Following timeline that merges every source into one stream — with ActivityPub posts rendered as social-style cards (avatar, display name, handle, relative time, "Reposted by …") so they don't look like blog entries dropped into a feed.
* A Favorites list so you can bookmark items permanently — they survive after the live feed rolls them off.
* A lightweight Custom Bar that replaces the WordPress admin bar on the front end with Home / Create / Profile shortcuts — the site feels like an app, not a CMS.
* Frontend post composer (a small inline editor) that publishes via the REST API.
* OPML import / export for moving in and out of other feed readers, plus a one-shot "import follows from a Mastodon account" tool.
* Block-theme-friendly: ships a Social Menu block, Following / Favorites link blocks for any Navigation block, and plugin-default block templates so the Following and Favorites archives have a working layout on any active theme.
* No extra database tables — everything lives as standard WordPress posts and meta, so it survives backups, exports, and WP-CLI.

== Installation ==

1. Install and activate Heckl Tools.
2. (Optional, recommended) Install and activate the [ActivityPub plugin](https://wordpress.org/plugins/activitypub/) so you can follow Fediverse accounts and let your own posts federate.
3. Visit *Heckl Tools* in the admin sidebar to set up your profile and connect feeds.
4. Install the Heckl theme (optional, recommended) for a better experience.

== Frequently Asked Questions ==

= Do I need a Mastodon account or my own ActivityPub server? =

No. With the optional ActivityPub plugin installed, your WordPress site itself becomes a Fediverse identity. You can follow people from Mastodon, Pixelfed, PeerTube, etc., without joining any other server.

= Is the ActivityPub plugin required? =

No, it's optional. Heckl Tools works without it — you can still follow RSS feeds and WordPress.com Reader subscriptions, import archives, manage favorites, and use the unified timeline. Without ActivityPub, the only thing you lose is Fediverse follows (and the ability to federate your own posts outward). If you later install the ActivityPub plugin, Fediverse functionality lights up automatically.

= Does it support feeds without ActivityPub? =

Yes. RSS / Atom feeds (with WebSub push when the publisher supports it) and WordPress.com Reader subscriptions work alongside ActivityPub follows on the same timeline.

= Where does my data go? =

It stays on your WordPress site. There's no Heckl Tools cloud service. The plugin contacts the third-party services listed below — and only those — only when you take an explicit action.

= Will I lose my data if I deactivate the plugin? =

Your feed items, follows, and favorites are stored as standard WordPress posts and meta, so they remain in the database after deactivation. Cron schedules and WebSub subscriptions are cleaned up on deactivation.

= What about when I uninstall the plugin entirely? =

By default, uninstall preserves your feed items, favorites, and follow lists — Heckl Tools is often used as a personal archive of content you've collected, so silently wiping that on uninstall would be a data-loss surprise. Only plugin-internal flags (cron coordination, transients, OAuth tokens) are removed.

If you'd rather have a clean wipe on uninstall, turn on *Settings → Following → "Delete all data when the plugin is uninstalled"* before removing the plugin. With that toggle on, uninstall also removes the feed items, follow records, favorites, custom taxonomies, and all plugin options.

= Will activating the plugin change my permalink settings? =

Only if you're still on the WordPress default ("Plain"). Pretty permalinks are required for the ActivityPub WebFinger endpoint and for our own `/following/` and `/favorites/` URLs to resolve, so on activation we set the permalink structure to `/%postname%/` when none is set. Any non-default permalink structure you've already chosen is left alone.

== External services ==

Heckl Tools does not include analytics or telemetry. It contacts external services only when you choose to connect, follow, import, or add something.

= ActivityPub / Fediverse servers =

If you install the optional ActivityPub plugin and follow a Fediverse account, your site contacts that account's server so the follow can be created and public posts can appear in your timeline.

What is sent: the Fediverse account you choose to follow and your site's public ActivityPub identity.
Service: each Fediverse server you choose to interact with.
Terms vary per instance.

= WordPress.com / WP.com Reader =

If you click "Connect WordPress.com", Heckl Tools connects to WordPress.com so your Reader subscriptions and recent Reader items can appear in your timeline.

To make this connection work without asking every site owner to register a WordPress.com app, the sign-in flow may pass through a Heckl Tools connection helper hosted on WordPress.com. The helper only completes the sign-in flow and returns the connection token to your site; it does not store the token.

What is sent: your WordPress.com authorization, the connection token after you approve access, and Reader requests needed to show your subscriptions and Reader items.
Service: WordPress.com.
Terms of service: https://wordpress.com/tos/.
Privacy policy: https://automattic.com/privacy/.

= Arbitrary RSS / Atom feed URLs =

If you add an RSS or Atom feed, or import an OPML file, your site fetches the feed URLs you provide so new items can appear in your timeline.

What is sent: requests from your site to the feed URLs you add.
Service: whichever publisher hosts the feed.

= WebSub hubs =

Some feeds advertise a WebSub hub, which can notify your site when new posts are published.

What is sent: a subscription request with your site's notification address.
Service: whichever WebSub hub the publisher uses (commonly Google's `pubsubhubbub.appspot.com` or `superfeedr.com`).

= Mastodon "import follows from account" =

If you use "Import follows from a Mastodon account", Heckl Tools fetches that account's public following list from the Mastodon server you name.

What is sent: the Mastodon account you choose to import from.
Service: the Mastodon instance you specify.

== Privacy ==

Heckl Tools does not collect usage analytics or telemetry. Feed items, follows, favorites, settings, and connection tokens are stored on your WordPress site.

== Screenshots ==

1. Welcome wizard — connect the Fediverse, add feeds, and publish your first post.
2. Profile settings — set the name, handle, bio, site logo, and cover photo shown on your social site.
3. Following settings — manage visibility, feeds, imports, exports, and the live feed list.

== Upgrade Notice ==

= 1.0.4 =
Initial WordPress.org directory release. Safe to update.

= 1.0.1 =
Maintenance release. Activation no longer changes your permalink settings unless you opt in, plus editor and packaging fixes. Safe to update.

= 1.0.0 =
First stable release. No prior version to upgrade from.

== Changelog ==

= 1.0.4 =
* Initial WordPress.org directory release.
* Improved ActivityPub defaults so Heckl Tools works simply on new sites without overwriting ActivityPub's own saved settings.
* Tightened escaping and release packaging for WordPress.org distribution.

= 1.0.1 =
* Activation no longer changes the site's permalink structure automatically; pretty permalinks are only applied when explicitly enabled.
* Heckl blocks are now loaded conditionally.
* Fixed an error message shown in the frontend editor.
* Build and packaging improvements.

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
* Compact admin under *Heckl Tools* with Profile and Following tabs.
* Onboarding wizard with one-click ActivityPub plugin install and add-feed flows.

== Source code ==

The unminified source for every script shipped in `build/` lives alongside it in `src/`.
