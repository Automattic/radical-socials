=== Radical Theme ===
Contributors:      automattic, mikachan, onemaggie
Tags:              block-themes, blog, photoblogging, three-columns, custom-colors, custom-menu, featured-images, full-site-editing, style-variations, translation-ready
Requires at least: 6.6
Tested up to:      6.9
Requires PHP:      7.4
Stable tag:        0.1.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

A block theme for personal feed readers and social-style content sites, with layouts tuned for photo grids, vertical video, and text timelines.

== Description ==

Radical Theme is a block theme designed for sites that present a stream of content rather than a traditional blog: photo grids, vertical video reels, micro-blog timelines, and feed-reader views. The default layout is a three-column structure (navigation / main feed / sidebar) with four packaged style variations (Skyline, Neon Reel, Gallery Pop, Timeline) so the same theme can present as Instagram-style, Tumblr-style, or text-feed-style without theme switching.

Works on its own as a regular block theme for any WordPress site. Pairs with the [Radical Socials plugin](https://wordpress.org/plugins/radical-socials/), which adds a Following timeline (RSS / ActivityPub / WordPress.com Reader), Favorites, archive importers for Instagram / Twitter / Bluesky / TikTok, and a frontend post composer — when that plugin is active, the theme's templates light up with feed-item layouts, taxonomy filters, and a social-style post composer; when it's absent, the theme renders a normal block-themed blog.

== Installation ==

1. In the WordPress admin, go to *Appearance → Themes → Add New*.
2. Search for "Radical Theme", install, and activate.
3. (Optional) Install the [Radical Socials plugin](https://wordpress.org/plugins/radical-socials/) for the full feed-reader / social-site experience.

== Frequently Asked Questions ==

= Do I need the Radical Socials plugin? =

No. The theme works standalone as a regular block theme. The plugin adds the Following timeline, Favorites, and archive importers; without it, the theme presents your posts and pages with the same three-column layout but no feed/follow features.

= Why are some templates ("Feed Items archive", "Favorites archive") not visible? =

Those templates target custom post types that the Radical Socials plugin registers. WordPress only routes URL requests to those templates when the matching post type exists, so they're inert without the plugin — installing the plugin lights them up automatically.

= Can I switch between the style variations? =

Yes. Open *Appearance → Editor → Styles* and pick from Skyline (default), Neon Reel (dark / vertical video), Gallery Pop (photo grid), or Timeline (text feed). Each adjusts colour palette and layout density without changing the underlying templates.

== Changelog ==

= 0.1.0 =
* Initial public release.
* Three-column block-theme layout with header part and left/right sidebar parts.
* Four style variations: Skyline, Neon Reel, Gallery Pop, Timeline.
* Templates for index, archive, single, page, search, 404, and author views.
* Companion templates for the Radical Socials plugin's feed-item and favorite custom post types (inert without the plugin).

== Copyright ==

Radical Theme is free software, and is released under the terms of the GNU General Public License version 2 or (at your option) any later version. See LICENSE for the full license text.
