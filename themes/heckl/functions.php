<?php

if ( ! function_exists( 'heckl_theme_style' ) ) :
	/**
	 * Enqueues the theme's style.css on the front end.
	 *
	 * Hooked on wp_enqueue_scripts (not after_setup_theme) — calling
	 * wp_enqueue_style any earlier triggers WP's "called incorrectly"
	 * notice and the stylesheet never actually lands in the page.
	 *
	 * Priority 20 (after the default 10) so it loads after Heckl Tools'
	 * frontend bundle, letting same-specificity theme overrides
	 * win against plugin defaults.
	 *
	 * @return void
	 */
	function heckl_theme_style() {
		wp_enqueue_style(
			'heckl-style',
			get_parent_theme_file_uri( 'style.css' ),
			array(),
			wp_get_theme()->get( 'Version' )
		);
		wp_style_add_data(
			'heckl-style',
			'path',
			get_parent_theme_file_path( 'style.css' )
		);
	}
endif;
add_action( 'wp_enqueue_scripts', 'heckl_theme_style', 20 );

if ( ! function_exists( 'heckl_is_tools_plugin_active' ) ) :
	/**
	 * Check whether Heckl Tools is active without depending on when its
	 * taxonomies are registered. The Site Editor can evaluate theme patterns
	 * before plugin-provided taxonomies are available to taxonomy_exists().
	 *
	 * @return bool
	 */
	function heckl_is_tools_plugin_active(): bool {
		$plugin_file = 'heckl-tools/heckl-tools.php';

		if ( defined( 'HECKL_REWRITE_VERSION' ) ) {
			return true;
		}

		if ( in_array( $plugin_file, (array) get_option( 'active_plugins', [] ), true ) ) {
			return true;
		}

		if ( is_multisite() ) {
			$network_active_plugins = (array) get_site_option( 'active_sitewide_plugins', [] );
			return isset( $network_active_plugins[ $plugin_file ] );
		}

		return false;
	}
endif;

if ( ! function_exists( 'heckl_theme_editor_inline_css' ) ) :
	/**
	 * Inject a small CSS rule into the block editor canvas (Site Editor
	 * iframe + post editor) without shipping a separate stylesheet.
	 *
	 * Attached to `enqueue_block_assets` (not `enqueue_block_editor_assets`)
	 * because the latter only fires for the editor's outer shell, not the
	 * iframe where block previews actually render. The `is_admin()` gate
	 * scopes this to the editor — without it the same rule would also
	 * land on the front end via the same hook.
	 *
	 * @return void
	 */
	function heckl_theme_editor_inline_css() {
		if ( ! is_admin() ) {
			return;
		}
		wp_add_inline_style( 'wp-block-library', '.rs-cover { overflow: visible; }' );
	}
endif;
add_action( 'enqueue_block_assets', 'heckl_theme_editor_inline_css' );
