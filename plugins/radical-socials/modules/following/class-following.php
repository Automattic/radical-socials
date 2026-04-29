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

		// Recurring background fetch.
		add_action( self::FETCH_HOOK, [ 'Radical_Socials_Feed_Fetcher', 'run' ] );

		// ActivityPub push: fire immediately when a new inbox activity is saved.
		add_action( 'save_post_ap_inbox', [ __CLASS__, 'on_activitypub_activity' ], 10, 2 );

		// Make all permalink references point to the original article URL.
		add_filter( 'post_type_link', [ __CLASS__, 'external_permalink' ], 10, 2 );
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
		register_taxonomy(
			'rs_source',
			'rs_feed_item',
			[
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_rest'       => true,
				'rewrite'            => false,
				'hierarchical'       => false,
				'labels'             => [
					'name'          => __( 'Feed Sources', 'radical-socials' ),
					'singular_name' => __( 'Feed Source', 'radical-socials' ),
				],
			]
		);
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
