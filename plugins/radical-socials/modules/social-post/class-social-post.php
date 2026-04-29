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

	const POST_TYPE = 'social-post';

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		register_post_type( self::POST_TYPE, [
			'labels'       => [
				'name'          => __( 'Social Posts', 'radical-socials' ),
				'singular_name' => __( 'Social Post', 'radical-socials' ),
			],
			'public'       => true,
			'show_in_rest' => true,
			'rest_base'    => 'social-posts',
			'supports'     => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
		] );

		register_taxonomy( 'social-tag', self::POST_TYPE, [
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

	public static function current_user_can_publish(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$post_type = get_post_type_object( self::POST_TYPE );

		if (
			! $post_type ||
			empty( $post_type->cap->create_posts ) ||
			empty( $post_type->cap->publish_posts )
		) {
			return false;
		}

		return (
			current_user_can( $post_type->cap->create_posts ) &&
			current_user_can( $post_type->cap->publish_posts )
		);
	}
}

Radical_Socials_Social_Post::init();
