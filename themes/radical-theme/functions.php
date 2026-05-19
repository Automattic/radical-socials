<?php

if ( ! function_exists( 'radical_theme_editor_style' ) ) :
	/**
	 * Enqueues editor-style.css in the editors.
	 *
	 * @since Radical Theme 0.1.0
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
add_action( 'after_setup_theme', 'radical_theme_style' );