<?php
/**
 * Instagram Post CPT
 *
 * Registers the `instagram-post` custom post type and its associated
 * meta fields. REST support is enabled so the front-end editor and
 * any future importers can communicate via the WP REST API.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Instagram_Post {

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		register_post_type( 'instagram-post', [
			'labels'       => [
				'name'          => __( 'Instagram Posts', 'radical-socials' ),
				'singular_name' => __( 'Instagram Post', 'radical-socials' ),
			],
			'public'       => true,
			'show_in_rest' => true,
			'rest_base'    => 'instagram-posts',
			'supports'     => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
		] );

		$metas = [
			'_instagram_media_type'  => 'string', // photo | video | reel | story
			'_instagram_location'    => 'string',
			'_instagram_tags'        => 'string', // space-separated hashtags
			'_instagram_original_id' => 'string',
			'_instagram_original_url' => 'string',
			'_instagram_original_date' => 'string', // ISO 8601
		];

		foreach ( $metas as $key => $type ) {
			register_post_meta( 'instagram-post', $key, [
				'show_in_rest' => true,
				'single'       => true,
				'type'         => $type,
				'default'      => '',
			] );
		}
	}
}

new Radical_Socials_Instagram_Post();
