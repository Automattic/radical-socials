<?php
/**
 * Register plugin-default block templates for the rs_feed_item and
 * rs_favorite archives.
 *
 * The plugin is intended to work on any active theme — but most themes
 * won't define `archive-rs_feed_item.html` / `archive-rs_favorite.html`,
 * so without a fallback /following and /favorites would render with the
 * theme's generic archive layout (missing sidebars, the rs feed query,
 * etc.). These plugin-registered templates fill the gap.
 *
 * The HTML files in plugins/radical-socials/templates/ are produced by
 * bin/build-templates.php from the companion radical-theme — they are
 * NOT hand-edited and they are gitignored. Run `npm run build:templates`
 * (or `npm run plugin-zip`, which calls it) to regenerate after any
 * theme change.
 *
 * WP's normal template-resolution order applies: if a theme provides its
 * own version at the same slug, the theme wins. This file just supplies
 * a default when nothing else does.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

/**
 * Map of plugin template slug → user-visible title/description shown in
 * the Site Editor. The HTML body is loaded at registration time from
 * plugins/radical-socials/templates/{slug}.html.
 */
function radical_socials_block_template_registry(): array {
	return [
		'archive-rs_feed_item' => [
			'title'       => __( 'Feed Archive', 'radical-socials' ),
			'description' => __( 'Default layout for the Feed archive when the active theme does not provide one.', 'radical-socials' ),
		],
		'archive-rs_favorite' => [
			'title'       => __( 'Favorites Archive', 'radical-socials' ),
			'description' => __( 'Default layout for the Favorites archive when the active theme does not provide one.', 'radical-socials' ),
		],
	];
}

function radical_socials_register_block_templates(): void {
	if ( ! function_exists( 'register_block_template' ) ) {
		return;
	}

	$dir = dirname( __DIR__, 2 ) . '/templates';

	foreach ( radical_socials_block_template_registry() as $slug => $meta ) {
		$file = $dir . '/' . $slug . '.html';
		if ( ! is_readable( $file ) ) {
			continue;
		}
		register_block_template(
			'radical-socials//' . $slug,
			[
				'title'       => $meta['title'],
				'description' => $meta['description'],
				'content'     => (string) file_get_contents( $file ),
			]
		);
	}
}
add_action( 'init', 'radical_socials_register_block_templates' );
