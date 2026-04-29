<?php
/**
 * Following
 *
 * Registers the rs_feed_item shadow CPT and rs_source taxonomy, the /following
 * rewrite rule, and the hooks that keep the feed populated.
 *
 * Feed updates arrive via three paths:
 *  1. ActivityPub inbox  — true push: hook fires when the ActivityPub plugin
 *                          stores a new incoming Create activity.
 *  2. RSS/WebSub         — push from hub for WebSub-capable feeds; hourly
 *                          background fetch for feeds without a hub.
 *  3. WP.com Reader      — included in the hourly background fetch.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Following {

	/** Maximum number of feed items to keep in the database. */
	const MAX_ITEMS = 500;

	/** Cron hook for recurring background fetches. */
	const FETCH_HOOK = 'rs_fetch_following';

	public static function init(): void {
		add_action( 'init',             [ __CLASS__, 'register_cpt'      ] );
		add_action( 'init',             [ __CLASS__, 'register_taxonomy' ] );
		add_action( 'init',             [ __CLASS__, 'ensure_page'       ] );
		add_action( 'init',             [ __CLASS__, 'schedule_recurring' ] );
		add_action( 'init',             [ __CLASS__, 'register_blocks'   ] );

		// Recurring background fetch.
		add_action( self::FETCH_HOOK, [ 'Radical_Socials_Feed_Fetcher', 'run' ] );

		// ActivityPub push: fire immediately when a new inbox activity is saved.
		add_action( 'save_post_ap_inbox', [ __CLASS__, 'on_activitypub_activity' ], 10, 2 );

		// Make all permalink references point to the original article URL.
		add_filter( 'post_type_link', [ __CLASS__, 'external_permalink' ], 10, 2 );

		// Infinite scroll on the /following page.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_infinite_scroll' ] );
		add_filter( 'render_block',       [ __CLASS__, 'wrap_following_query' ], 10, 2 );
	}

	public static function register_blocks(): void {
		register_block_type( __DIR__ . '/blocks/favorite-feeds' );
		register_block_type( __DIR__ . '/blocks/following-link' );
		add_filter( 'hooked_block_types', [ __CLASS__, 'hook_following_link' ], 10, 3 );
	}

	public static function hook_following_link( array $hooked_blocks, string $position, ?string $anchor_block ): array {
		if ( 'last_child' === $position && 'core/navigation' === $anchor_block ) {
			$hooked_blocks[] = 'radical-socials/following-link';
		}
		return $hooked_blocks;
	}

	public static function schedule_recurring(): void {
		if ( ! wp_next_scheduled( self::FETCH_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::FETCH_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::FETCH_HOOK );
	}

	public static function external_permalink( string $url, WP_Post $post ): string {
		if ( 'rs_feed_item' !== $post->post_type ) {
			return $url;
		}
		$external = get_post_meta( $post->ID, '_rs_item_url', true );
		return $external ?: $url;
	}

	public static function register_cpt(): void {
		register_post_type(
			'rs_feed_item',
			[
				'public'              => false,
				'publicly_queryable'  => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => true,
				'query_var'           => false,
				'rewrite'             => false,
				'capability_type'     => 'post',
				'has_archive'         => false,
				'hierarchical'        => false,
				'exclude_from_search' => true,
				'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
				'labels'              => [
					'name'          => __( 'Feed Items', 'radical-socials' ),
					'singular_name' => __( 'Feed Item', 'radical-socials' ),
				],
			]
		);
	}

	public static function register_taxonomy(): void {
		$shared = [
			'public'             => false,
			'publicly_queryable' => true,
			'show_ui'            => false,
			'show_in_rest'       => true,
			'rewrite'            => false,
			'hierarchical'       => false,
		];

		register_taxonomy( 'rs_source', 'rs_feed_item', array_merge( $shared, [
			'labels' => [
				'name'          => __( 'Feed Sources', 'radical-socials' ),
				'singular_name' => __( 'Feed Source', 'radical-socials' ),
			],
		] ) );

		register_taxonomy( 'rs_feed_type', 'rs_feed_item', array_merge( $shared, [
			'labels' => [
				'name'          => __( 'Feed Types', 'radical-socials' ),
				'singular_name' => __( 'Feed Type', 'radical-socials' ),
			],
		] ) );
	}

	/**
	 * Create the /following page if it doesn't exist. Idempotent — safe to call on every init.
	 * FSE automatically uses page-following.html for a page with slug 'following'.
	 */
	public static function ensure_page(): void {
		if ( get_page_by_path( 'following', OBJECT, 'page' ) ) {
			return;
		}
		wp_insert_post( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_name'   => 'following',
			'post_title'  => __( 'Following', 'radical-socials' ),
			'post_content' => '',
		] );
	}

	public static function enqueue_infinite_scroll(): void {
		if ( ! is_page( 'following' ) ) {
			return;
		}
		wp_enqueue_script_module(
			'radical-socials/following',
			plugin_dir_url( dirname( dirname( __DIR__ ) ) . '/radical-socials.php' ) . 'modules/following/assets/infinite-scroll.js',
			[ '@wordpress/interactivity' ],
			filemtime( __DIR__ . '/assets/infinite-scroll.js' ) ?: '1'
		);
	}

	/**
	 * Wraps the /following query block in an Interactivity API region so the
	 * infinite-scroll store can read total pages and append new items.
	 */
	public static function wrap_following_query( string $html, array $block ): string {
		if ( ! is_page( 'following' ) ) {
			return $html;
		}
		if ( 'core/query' !== $block['blockName'] ) {
			return $html;
		}
		if ( ! str_contains( $block['attrs']['className'] ?? '', 'rs-following-feed' ) ) {
			return $html;
		}

		$per_page  = (int) ( $block['attrs']['query']['perPage'] ?? 20 );
		$query_id  = (int) ( $block['attrs']['queryId'] ?? 0 );
		$total     = (int) ( wp_count_posts( 'rs_feed_item' )->publish ?? 0 );
		$max_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

		$context  = wp_json_encode( [
			'page'     => 1,
			'maxPages' => $max_pages,
			'queryId'  => $query_id,
			'loading'  => false,
		] );
		$sentinel = '<div class="rs-following-sentinel" data-wp-init="callbacks.observeSentinel" aria-hidden="true"></div>';

		return '<div data-wp-interactive="radical-socials/following" data-wp-context=\'' . esc_attr( $context ) . '\'>'
			. $html
			. $sentinel
			. '</div>';
	}

	/**
	 * ActivityPub push handler — called synchronously by WordPress when the
	 * ActivityPub plugin saves a new incoming activity post. Ingests it into
	 * the rs_feed_item CPT immediately so it appears in the feed at once.
	 */
	public static function on_activitypub_activity( int $post_id, WP_Post $post ): void {
		// Only process Create activities (new content, not likes/announces).
		$activity_type = get_post_meta( $post_id, '_ap_activity_type', true );
		if ( $activity_type && 'Create' !== $activity_type ) {
			return;
		}

		$items = Radical_Socials_ActivityPub_Fetcher::normalize_activity( $post_id );
		if ( $items ) {
			Radical_Socials_Feed_Fetcher::upsert_item( $items );
			Radical_Socials_Feed_Fetcher::enforce_cap();
		}
	}
}

Radical_Socials_Following::init();
