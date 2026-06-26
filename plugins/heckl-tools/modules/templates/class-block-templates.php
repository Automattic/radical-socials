<?php
/**
 * Register plugin-default block templates for the heckl_feed_item and
 * heckl_favorite archives.
 *
 * The plugin is intended to work on any active theme — but most themes
 * won't define `archive-heckl_feed_item.html` / `archive-heckl_favorite.html`,
 * so without a fallback /following and /favorites would render with the
 * theme's generic archive layout (missing sidebars, the rs feed query,
 * etc.). These plugin-registered templates fill the gap.
 *
 * The HTML files in plugins/heckl/templates/ are produced by
 * bin/build-templates.php from the companion heckl — they are
 * NOT hand-edited and they are gitignored. Run `npm run build:templates`
 * (or `npm run plugin-zip`, which calls it) to regenerate after any
 * theme change.
 *
 * WP's normal template-resolution order applies: if a theme provides its
 * own version at the same slug, the theme wins. This file just supplies
 * a default when nothing else does.
 *
 * @package Heckl
 */

defined( 'ABSPATH' ) || exit;

/**
 * Map of plugin template slug → user-visible title/description shown in
 * the Site Editor. The HTML body is loaded at registration time from
 * plugins/heckl/templates/{slug}.html.
 */
function heckl_block_template_registry(): array {
	return [
		'archive-heckl_feed_item' => [
			'title'       => __( 'Feed Archive', 'heckl-tools' ),
			'description' => __( 'Default layout for the Feed archive when the active theme does not provide one.', 'heckl-tools' ),
		],
		'archive-heckl_favorite' => [
			'title'       => __( 'Favorites Archive', 'heckl-tools' ),
			'description' => __( 'Default layout for the Favorites archive when the active theme does not provide one.', 'heckl-tools' ),
		],
	];
}

function heckl_register_block_templates(): void {
	if ( ! function_exists( 'register_block_template' ) ) {
		return;
	}

	$dir = dirname( __DIR__, 2 ) . '/templates';

	foreach ( heckl_block_template_registry() as $slug => $meta ) {
		$file = $dir . '/' . $slug . '.html';
		if ( ! is_readable( $file ) ) {
			continue;
		}
		register_block_template(
			'heckl//' . $slug,
			[
				'title'       => $meta['title'],
				'description' => $meta['description'],
				'content'     => (string) file_get_contents( $file ),
			]
		);
	}
}
add_action( 'init', 'heckl_register_block_templates' );
