<?php
/**
 * Following
 *
 * Registers the rs_feed_item CPT (archive at /following/) and rs_source
 * taxonomy, and the hooks that keep the feed populated.
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
		add_action( 'init',             [ __CLASS__, 'register_cpt'        ] );
		add_action( 'init',             [ __CLASS__, 'register_taxonomy'   ] );
		add_action( 'init',             [ __CLASS__, 'schedule_recurring'  ] );
		add_action( 'init',             [ __CLASS__, 'register_blocks'     ] );

		// Recurring background fetch.
		add_action( self::FETCH_HOOK, [ 'Radical_Socials_Feed_Fetcher', 'run' ] );

		// ActivityPub push: fire immediately when a new inbox activity is saved.
		add_action( 'save_post_ap_inbox', [ __CLASS__, 'on_activitypub_activity' ], 10, 2 );

		// Make all permalink references point to the original article URL.
		add_filter( 'post_type_link', [ __CLASS__, 'external_permalink' ], 10, 2 );

		// Infinite scroll on feed archive pages.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_infinite_scroll' ] );
		add_action( 'template_redirect',  [ __CLASS__, 'maybe_add_block_filter'  ] );

		// Visit-triggered background refresh: kick off a fetch on any frontend or
		// admin page load when the feed hasn't been updated in the last 15 minutes.
		add_action( 'template_redirect', [ __CLASS__, 'maybe_refresh_feed' ] );
		add_action( 'admin_init',        [ __CLASS__, 'maybe_refresh_feed' ] );
	}

	// ── Blocks ────────────────────────────────────────────────────────────────

	public static function register_blocks(): void {
		register_block_type( __DIR__ . '/blocks/favorite-feeds' );
		register_block_type( __DIR__ . '/blocks/following-link' );
		register_block_type( __DIR__ . '/blocks/favorites-link' );
		register_block_type( __DIR__ . '/blocks/like-button' );
		add_filter( 'hooked_block_types', [ __CLASS__, 'hook_following_link' ], 10, 3 );
		add_filter( 'hooked_block_types', [ __CLASS__, 'hook_favorites_link' ], 10, 3 );
		add_filter( 'hooked_block_types', [ __CLASS__, 'hook_like_button'    ], 10, 3 );
	}

	public static function hook_following_link( array $hooked_blocks, string $position, ?string $anchor_block ): array {
		if ( 'last_child' === $position && 'core/navigation' === $anchor_block ) {
			$hooked_blocks[] = 'radical-socials/following-link';
		}
		return $hooked_blocks;
	}

	public static function hook_favorites_link( array $hooked_blocks, string $position, ?string $anchor_block ): array {
		if ( 'last_child' === $position && 'core/navigation' === $anchor_block ) {
			$hooked_blocks[] = 'radical-socials/favorites-link';
		}
		return $hooked_blocks;
	}

	public static function hook_like_button( array $hooked_blocks, string $position, ?string $anchor_block ): array {
		if ( 'last_child' === $position && 'core/post-template' === $anchor_block ) {
			$hooked_blocks[] = 'radical-socials/like-button';
		}
		return $hooked_blocks;
	}

	// ── Cron ──────────────────────────────────────────────────────────────────

	public static function schedule_recurring(): void {
		if ( ! wp_next_scheduled( self::FETCH_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::FETCH_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::FETCH_HOOK );
	}

	// ── CPT & taxonomy ────────────────────────────────────────────────────────

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
				'rewrite'             => [ 'slug' => 'following', 'with_front' => false ],
				'capability_type'     => 'post',
				'has_archive'         => true,
				'hierarchical'        => false,
				'exclude_from_search' => true,
				'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
				'labels'              => [
					'name'          => __( 'Following', 'radical-socials' ),
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

		register_taxonomy( 'rs_feed_category', 'rs_feed_item', array_merge( $shared, [
			'labels' => [
				'name'          => __( 'Feed Categories', 'radical-socials' ),
				'singular_name' => __( 'Feed Category', 'radical-socials' ),
			],
		] ) );
	}

	// ── Feed refresh ──────────────────────────────────────────────────────────

	/**
	 * Schedule a background feed fetch on any page load when the feed is stale
	 * (older than 15 minutes). Uses a 10-minute transient lock to prevent
	 * concurrent page loads from stacking up multiple fetches.
	 */
	public static function maybe_refresh_feed(): void {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$last        = (int) get_option( 'rs_last_feed_fetch', 0 );
		$stale_after = 15 * MINUTE_IN_SECONDS;

		if ( time() - $last < $stale_after ) {
			return;
		}
		if ( get_transient( 'rs_feed_refresh_lock' ) ) {
			return;
		}
		set_transient( 'rs_feed_refresh_lock', 1, 10 * MINUTE_IN_SECONDS );

		wp_clear_scheduled_hook( self::FETCH_HOOK );
		wp_schedule_single_event( time() - 1, self::FETCH_HOOK );
		spawn_cron();
	}

	public static function refresh_secret(): string {
		return wp_hash( 'rs_feed_refresh_' . wp_salt() );
	}

	// ── Frontend ──────────────────────────────────────────────────────────────

	public static function enqueue_infinite_scroll(): void {
		if ( ! is_post_type_archive( [ 'rs_feed_item', 'rs_favorite' ] ) ) {
			return;
		}
		$plugin_url = plugin_dir_url( dirname( dirname( __DIR__ ) ) . '/radical-socials.php' );
		wp_enqueue_script_module(
			'radical-socials/following',
			$plugin_url . 'modules/following/assets/infinite-scroll.js',
			[ '@wordpress/interactivity' ],
			filemtime( __DIR__ . '/assets/infinite-scroll.js' ) ?: '1'
		);
		wp_enqueue_style(
			'radical-socials/following',
			$plugin_url . 'modules/following/assets/following.css',
			[],
			filemtime( __DIR__ . '/assets/following.css' ) ?: '1'
		);
	}

	public static function maybe_add_block_filter(): void {
		if ( is_post_type_archive( [ 'rs_feed_item', 'rs_favorite' ] ) ) {
			add_filter( 'render_block', [ __CLASS__, 'wrap_following_query' ], 10, 2 );
		}
	}

	/**
	 * Adds Interactivity API directives to the feed query block so the
	 * infinite-scroll store can read total pages and append new items.
	 *
	 * Attributes are injected directly on the block's outer element (via
	 * WP_HTML_Tag_Processor) rather than adding an extra wrapper div, so the
	 * site editor can still correctly identify and select the block.
	 */
	public static function wrap_following_query( string $html, array $block ): string {
		if ( 'core/query' !== $block['blockName'] ) {
			return $html;
		}
		if ( ! str_contains( $block['attrs']['className'] ?? '', 'rs-following-feed' ) ) {
			return $html;
		}

		$post_type = is_post_type_archive( 'rs_favorite' ) ? 'rs_favorite' : 'rs_feed_item';
		$per_page  = (int) ( $block['attrs']['query']['perPage'] ?? 20 );
		$query_id  = (int) ( $block['attrs']['queryId'] ?? 0 );
		$total     = (int) ( wp_count_posts( $post_type )->publish ?? 0 );
		$max_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

		$context  = wp_json_encode( [
			'page'     => 1,
			'maxPages' => $max_pages,
			'queryId'  => $query_id,
			'loading'  => false,
		] );
		$sentinel = '<div class="rs-following-sentinel" data-wp-init="callbacks.observeSentinel" aria-hidden="true"></div>';

		// Refresh is only relevant on the following feed, not favorites.
		$is_following = is_post_type_archive( 'rs_feed_item' );

		wp_interactivity_state( 'radical-socials/following', [
			'refreshUrl'      => rest_url( 'radical-socials/v1/following/refresh' ),
			'nonce'           => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'canRefresh'      => is_user_logged_in() && $is_following,
			'refreshing'      => false,
			'pulling'         => false,
			'refreshLabel'    => __( 'Refresh feed', 'radical-socials' ),
			'noNewPostsLabel' => __( 'No new posts', 'radical-socials' ),
			'newPostLabel'    => __( '1 new post', 'radical-socials' ),
			'newPostsLabel'   => __( '%d new posts', 'radical-socials' ),
		] );

		$pull_indicator = ( is_user_logged_in() && $is_following )
			? '<div class="rs-refresh-bar" data-wp-class--rs-pull-refreshing="state.refreshing">'
				. '<button class="rs-refresh-btn" data-wp-on--click="actions.refresh" data-wp-bind--disabled="state.refreshing">'
					. '<span class="rs-pull-arrow" aria-hidden="true">↻</span>'
					. '<span class="rs-pull-spinner" aria-hidden="true"></span>'
					. '<span class="rs-refresh-label">' . esc_html__( 'Refresh feed', 'radical-socials' ) . '</span>'
				. '</button>'
			. '</div>'
			: '';

		// Add Interactivity API attributes directly to the query block's outer
		// element so the site editor can still map the DOM node to the block.
		$processor = new WP_HTML_Tag_Processor( $html );
		if ( $processor->next_tag() ) {
			$processor->set_attribute( 'data-wp-interactive', 'radical-socials/following' );
			$processor->set_attribute( 'data-wp-context', $context );
			if ( is_user_logged_in() && $is_following ) {
				$processor->set_attribute( 'data-wp-init', 'callbacks.initPullToRefresh' );
			}
		}
		$html = $processor->get_updated_html();

		// Insert pull indicator after the opening tag (top of feed).
		if ( $pull_indicator ) {
			$first_close = strpos( $html, '>' );
			if ( $first_close !== false ) {
				$html = substr( $html, 0, $first_close + 1 )
					. $pull_indicator
					. substr( $html, $first_close + 1 );
			}
		}

		// Insert sentinel before the block's final closing tag (bottom of feed).
		$last_div = strrpos( $html, '</div>' );
		if ( $last_div !== false ) {
			$html = substr( $html, 0, $last_div ) . $sentinel . substr( $html, $last_div );
		}

		return $html;
	}

	// ── ActivityPub push ──────────────────────────────────────────────────────

	/**
	 * Called synchronously when the ActivityPub plugin saves a new inbox
	 * activity. Ingests it into the rs_feed_item CPT immediately.
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
