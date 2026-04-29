<?php
/**
 * Social Post CPT
 *
 * Registers the `social-post` custom post type and its associated
 * meta fields. REST support is enabled so the front-end editor and
 * any future importers can communicate via the WP REST API.
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

		$metas = [
			'_social_tags'     => 'string', // space-separated hashtags
			'_social_location' => 'string',
		];

		foreach ( $metas as $key => $type ) {
			register_post_meta( 'social-post', $key, [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => $type,
				'default'       => '',
				'auth_callback' => function( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			] );
		}
	}
}

Radical_Socials_Social_Post::init();
