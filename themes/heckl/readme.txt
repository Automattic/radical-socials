=== Heckl ===
Contributors: automattic, mikachan, onemaggie
Tags: blog, style-variations, three-columns, custom-colors, custom-menu, featured-images, full-site-editing, translation-ready
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A block theme for personal feed readers and social-style content sites, with layouts tuned for photo grids, vertical video, and text timelines.

== Description ==

Heckl is a block theme designed for sites that present a stream of content rather than a traditional blog: photo grids, vertical video reels, micro-blog timelines, and feed-reader views. The default layout is a three-column structure (navigation / main feed / sidebar) with four packaged style variations (Skyline, Neon Reel, Gallery Pop, Timeline) so the same theme can present as Instagram-style, Tumblr-style, or text-feed-style without theme switching.

Works on its own as a regular block theme for any WordPress site. Pairs with the [Heckl Tools plugin](https://wordpress.org/plugins/heckl-tools/), which adds a Following timeline (RSS / ActivityPub / WordPress.com Reader), a Favorites bookmark list, and a frontend post composer — when that plugin is active, the theme's templates light up with feed-item layouts, taxonomy filters, and a social-style post composer; when it's absent, the theme renders a normal block-themed blog.

== Installation ==

1. In the WordPress admin, go to *Appearance → Themes → Add New*.
2. Search for "Heckl", install, and activate.
3. (Optional) Install the [Heckl Tools plugin](https://wordpress.org/plugins/heckl-tools/) for the full feed-reader / social-site experience.

== Frequently Asked Questions ==

= Do I need the Heckl plugin? =

No. The theme works standalone as a regular block theme. Heckl Tools adds the Following timeline, Favorites bookmark list, and frontend post composer; without it, the theme presents your posts and pages with the same three-column layout but no feed/follow features.

= Why are some templates ("Feed Items archive", "Favorites archive") not visible? =

Those templates target custom post types that the Heckl plugin registers. WordPress only routes URL requests to those templates when the matching post type exists, so they're inert without the plugin — installing the plugin lights them up automatically.

= Can I switch between the style variations? =

Yes. Open *Appearance → Editor → Styles* and pick from Skyline (default), Neon Reel (dark / vertical video), Gallery Pop (photo grid), or Timeline (text feed). Each adjusts colour palette and layout density without changing the underlying templates.

== Changelog ==

= 1.0.0 =
* First stable release.
* Three-column block-theme layout (left sidebar / main feed / right sidebar) with shared header and footer parts.
* Four style variations: Skyline (default), Neon Reel (dark, vertical video), Gallery Pop (photo grid), Timeline (text feed).
* Templates for index, archive, single, page, search, 404, and author views.
* Companion templates for the Heckl plugin's feed-item and favorite custom post types (inert without the plugin). When the plugin is present and the theme is missing one of these templates, the plugin registers its own flattened version of the theme's source so the archives still work — see the Heckl build pipeline.

== Copyright ==

Heckl WordPress Theme, (C) 2026 Automattic.
Heckl is distributed under the terms of the GNU GPL.

Heckl is free software, and is released under the terms of the GNU General Public License version 2 or (at your option) any later version.

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

Heckl bundles the following resources:

=== Images ===

Screenshot image (screenshot.jpg)
Copyright 2026 Sarah Norris
License: GPL-2.0-or-later
Source: Photograph taken by Sarah Norris, created for this theme.
