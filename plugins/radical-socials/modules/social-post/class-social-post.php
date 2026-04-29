<?php
/**
 * Social Post CPT
 *
 * Registers the `social-post` custom post type and the `social-tag` taxonomy.
 * REST support is enabled so the front-end editor and any future importers
 * can communicate via the WP REST API.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Social_Post {

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		register_post_type( 'social-post', [
			'labels'       => [
				'name'          => __( 'Social Posts', 'radical-socials' ),
				'singular_name' => __( 'Social Post', 'radical-socials' ),
			],
			'public'       => true,
			'show_in_rest' => true,
			'rest_base'    => 'social-posts',
			'supports'     => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
		] );

		register_taxonomy( 'social-tag', 'social-post', [
			'labels'       => [
				'name'          => __( 'Social Tags', 'radical-socials' ),
				'singular_name' => __( 'Social Tag', 'radical-socials' ),
			],
			'public'       => true,
			'show_in_rest' => true,
			'rest_base'    => 'social-tags',
			'hierarchical' => false,
		] );
	}
}

Radical_Socials_Social_Post::init();
