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
			'radical-theme-style',
			get_parent_theme_file_uri( 'style.css' ),
			array(),
			wp_get_theme()->get( 'Version' )
		);
		wp_style_add_data(
			'radical-theme-style',
			'path',
			get_parent_theme_file_path( 'style.css' )
		);
	}
endif;
add_action( 'wp_enqueue_scripts', 'radical_theme_style', 20 );