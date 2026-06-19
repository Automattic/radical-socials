<?php

if ( ! function_exists( 'radical_theme_style' ) ) :
	/**
	 * Enqueues the theme's style.css on the front end.
	 *
	 * Hooked on wp_enqueue_scripts (not after_setup_theme) — calling
	 * wp_enqueue_style any earlier triggers WP's "called incorrectly"
	 * notice and the stylesheet never actually lands in the page.
	 *
	 * Priority 20 (after the default 10) so it loads after Radical
	 * Socials' frontend bundle, letting same-specificity theme overrides
	 * win against plugin defaults.
	 *
	 * @return void
	 */
	function radical_theme_style() {
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
add_action( 'wp_enqueue_scripts', 'radical_theme_style', 20 );

if ( ! function_exists( 'radical_theme_editor_inline_css' ) ) :
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
	function radical_theme_editor_inline_css() {
		if ( ! is_admin() ) {
			return;
		}
		wp_add_inline_style( 'wp-block-library', '.rs-cover { overflow: visible; }' );
	}
endif;
add_action( 'enqueue_block_assets', 'radical_theme_editor_inline_css' );