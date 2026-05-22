<?php
/**
 * Favorites
 *
 * Registers the rs_favorite CPT — a permanent snapshot of a feed item that the
 * site owner has liked. Favorites survive the rs_feed_item rolling cap and form
 * the data source for a future /favorites page.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Favorites {

	const CPT = 'rs_favorite';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_cpt'      ] );
		add_action( 'init', [ __CLASS__, 'extend_taxonomies' ], 11 ); // after Following registers them
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_filter( 'post_type_link', [ __CLASS__, 'external_permalink' ], 10, 2 );
	}

	public static function register_cpt(): void {
		register_post_type(
			self::CPT,
			[
				'public'              => false,
				'publicly_queryable'  => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => true,
				'query_var'           => false,
				'rewrite'             => [ 'slug' => 'favorites', 'with_front' => false ],
				'capability_type'     => 'post',
				'has_archive'         => true,
				'hierarchical'        => false,
				'exclude_from_search' => true,
				'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
				'labels'              => [
					'name'          => __( 'Favorites', 'radical-socials-tools' ),
					'singular_name' => __( 'Favorite', 'radical-socials-tools' ),
				],
			]
		);
	}

	/**
	 * Attach the shared feed taxonomies to rs_favorite so the favorites feed
	 * can be filtered by source, type, and category — same as the following feed.
	 */
	public static function extend_taxonomies(): void {
		foreach ( [ 'rs_source', 'rs_feed_type', 'rs_feed_category' ] as $taxonomy ) {
			register_taxonomy_for_object_type( $taxonomy, self::CPT );
		}
	}

	/**
	 * Create the /favorites page if it doesn't exist. Idempotent.
	 */
	public static function ensure_page(): void {
		if ( get_page_by_path( 'favorites', OBJECT, 'page' ) ) {
			return;
		}
		wp_insert_post( [
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => 'favorites',
			'post_title'   => __( 'Favorites', 'radical-socials-tools' ),
			'post_content' => '',
		] );
	}

	/**
	 * Redirect rs_favorite permalink clicks to the original article URL.
	 */
	public static function external_permalink( string $url, WP_Post $post ): string {
		if ( self::CPT !== $post->post_type ) {
			return $url;
		}
		$external = get_post_meta( $post->ID, '_rs_item_url', true );
		return $external ?: $url;
	}

	// ── REST ─────────────────────────────────────────────────────────────────

	public static function register_routes(): void {
		register_rest_route( 'radical-socials/v1', '/favorites/toggle', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_toggle' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'args'                => [
				'post_id' => [
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
			],
		] );
	}

	public static function handle_toggle( WP_REST_Request $request ): WP_REST_Response {
		$feed_item_id = $request->get_param( 'post_id' );
		$feed_item    = get_post( $feed_item_id );

		if ( ! $feed_item || 'rs_feed_item' !== $feed_item->post_type ) {
			return new WP_REST_Response( [ 'error' => 'invalid_post' ], 400 );
		}

		$item_url = get_post_meta( $feed_item_id, '_rs_item_url', true );
		$slug     = md5( $item_url ?: (string) $feed_item_id );

		$existing = get_posts( [
			'post_type'     => self::CPT,
			'post_status'   => 'any',
			'name'          => $slug,
			'fields'        => 'ids',
			'numberposts'   => 1,
			'no_found_rows' => true,
		] );

		if ( $existing ) {
			wp_delete_post( (int) $existing[0], true );
			return new WP_REST_Response( [ 'favorited' => false ], 200 );
		}

		// Snapshot the feed item so it persists after the rolling cap removes it.
		$fav_id = wp_insert_post( [
			'post_type'    => self::CPT,
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $feed_item->post_title,
			'post_content' => $feed_item->post_content,
			'post_excerpt' => $feed_item->post_excerpt,
			'post_date'    => $feed_item->post_date,
			'post_author'  => get_current_user_id(),
			'meta_input'   => [
				'_rs_item_url'        => $item_url,
				'_rs_item_source_url' => get_post_meta( $feed_item_id, '_rs_item_source_url', true ),
				'_rs_item_thumbnail'  => get_post_meta( $feed_item_id, '_rs_item_thumbnail', true ),
				'_rs_item_feed_type'  => get_post_meta( $feed_item_id, '_rs_item_feed_type', true ),
			],
		] );

		if ( is_wp_error( $fav_id ) ) {
			return new WP_REST_Response( [ 'error' => 'insert_failed' ], 500 );
		}

		foreach ( [ 'rs_source', 'rs_feed_type', 'rs_feed_category' ] as $taxonomy ) {
			$terms = wp_get_post_terms( $feed_item_id, $taxonomy, [ 'fields' => 'names' ] );
			if ( $terms && ! is_wp_error( $terms ) ) {
				wp_set_post_terms( $fav_id, $terms, $taxonomy );
			}
		}

		return new WP_REST_Response( [ 'favorited' => true ], 201 );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public static function is_favorited( int $feed_item_id ): bool {
		$url = get_post_meta( $feed_item_id, '_rs_item_url', true );
		if ( ! $url ) {
			return false;
		}
		return (bool) get_posts( [
			'post_type'     => self::CPT,
			'post_status'   => 'publish',
			'name'          => md5( $url ),
			'fields'        => 'ids',
			'numberposts'   => 1,
			'no_found_rows' => true,
		] );
	}
}

Radical_Socials_Favorites::init();
