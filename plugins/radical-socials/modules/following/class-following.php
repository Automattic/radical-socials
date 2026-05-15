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
 *  2. RSS/WebSub         — push from hub for WebSub-capable feeds; 15-minute
 *                          background fetch and manual refresh otherwise.
 *  3. WP.com Reader      — included in recurring/manual background fetches.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Following {

	/** Maximum number of feed items to keep in the database. */
	const MAX_ITEMS = 500;

	/** Cron hook for recurring background fetches. */
	const FETCH_HOOK = 'rs_fetch_following';

	/** Recurring schedule for social-style feed refreshes. */
	const FETCH_SCHEDULE = 'rs_every_15_minutes';
	const FETCH_INTERVAL = 15 * MINUTE_IN_SECONDS;

	/** Cron hook for immediate manual refresh fetches. */
	const REFRESH_HOOK = 'rs_refresh_following_now';

	/** Transient lock used to prevent overlapping feed fetches. */
	const REFRESH_LOCK = 'rs_feed_refresh_lock';

	const REFRESH_LOCK_QUEUED  = 'queued';
	const REFRESH_LOCK_RUNNING = 'running';

	/** Maximum time to hold the refresh lock if a fetch does not finish cleanly. */
	const REFRESH_LOCK_TTL = 600;

	public static function init(): void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedules' ] );

		add_action( 'init',             [ __CLASS__, 'register_cpt'        ] );
		add_action( 'init',             [ __CLASS__, 'register_taxonomy'   ] );
		add_action( 'init',             [ __CLASS__, 'schedule_recurring'  ] );
		add_action( 'init',             [ __CLASS__, 'register_blocks'     ] );

		// Recurring background fetch.
		add_action( self::FETCH_HOOK, [ 'Radical_Socials_Feed_Fetcher', 'run' ] );
		add_action( self::REFRESH_HOOK, [ 'Radical_Socials_Feed_Fetcher', 'run' ] );

		// ActivityPub push: fire immediately when a new inbox activity is saved.
		add_action( 'save_post_ap_inbox', [ __CLASS__, 'on_activitypub_activity' ], 10, 2 );

		// Make all permalink references point to the original article URL.
		add_filter( 'post_type_link', [ __CLASS__, 'external_permalink' ], 10, 2 );

		// Force rs_feed_item as the queried post type when one of our taxonomies
		// is in play — rs_feed_item has exclude_from_search=true, so WP's tax
		// archive fallback would otherwise pick post/page/attachment and find 0
		// results.
		add_action( 'pre_get_posts', [ __CLASS__, 'force_feed_item_for_taxonomy_archives' ] );

		// The feed Query block has inherit=false (so the site editor preview
		// works), which means it ignores URL-based taxonomy filters by default.
		// Merge the current term back in when the archive template renders on
		// one of our taxonomies.
		add_filter( 'query_loop_block_query_vars', [ __CLASS__, 'inherit_taxonomy_in_feed_query' ], 10, 3 );

		// Suppress core/post-title for ActivityPub feed items (notes don't have
		// titles) and for any feed item whose title is empty.
		add_filter( 'render_block_core/post-title', [ __CLASS__, 'hide_empty_or_activitypub_titles' ], 10, 2 );

		// Gate /following/ and rs_feed_item single views for logged-out users
		// when the site owner hasn't opted into a public following page.
		add_action( 'template_redirect', [ __CLASS__, 'gate_following_access' ], 0 );

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
		register_block_type( __DIR__ . '/blocks/feed-author-name' );
		register_block_type( __DIR__ . '/blocks/feed-author-avatar' );
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

	public static function add_cron_schedules( array $schedules ): array {
		$schedules[ self::FETCH_SCHEDULE ] = [
			'interval' => self::FETCH_INTERVAL,
			'display'  => __( 'Every 15 minutes', 'radical-socials' ),
		];
		return $schedules;
	}

	public static function schedule_recurring(): void {
		$next             = wp_next_scheduled( self::FETCH_HOOK );
		$current_schedule = $next ? wp_get_schedule( self::FETCH_HOOK ) : false;

		if ( $next && self::FETCH_SCHEDULE !== $current_schedule ) {
			wp_clear_scheduled_hook( self::FETCH_HOOK );
			$next = false;
		}

		if ( ! $next ) {
			wp_schedule_event( time() + self::FETCH_INTERVAL, self::FETCH_SCHEDULE, self::FETCH_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::FETCH_HOOK );
		wp_clear_scheduled_hook( self::REFRESH_HOOK );
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

	/**
	 * Force rs_feed_item as the queried post type for archives on our
	 * taxonomies (rs_source, rs_feed_type, rs_feed_category).
	 *
	 * Why: WP_Query's taxonomy-archive fallback uses
	 * `get_post_types(['exclude_from_search' => false])` to decide which post
	 * types to include. rs_feed_item has exclude_from_search=true, so it's
	 * skipped and WP falls back to post/page/attachment — none of which have
	 * our taxonomies, hence 0 results.
	 */
	/**
	 * Merge any of our taxonomy filters present on the current URL into the
	 * rs-following-feed Query block's WP_Query args. The template hardcodes
	 * inherit=false (so the site editor can resolve a preview), which means
	 * the block otherwise ignores the URL's taxonomy filter.
	 *
	 * Reads query vars directly because URLs like `/?rs_feed_type=rss` set
	 * the taxonomy as a filter on the home query rather than triggering a
	 * proper tax archive (we don't register a rewrite for our taxonomies), so
	 * get_queried_object() doesn't return a WP_Term in that case.
	 */
	public static function inherit_taxonomy_in_feed_query( array $query, $block, int $page ): array {
		// The filter fires while rendering core/post-template, which inherits
		// the parent Query block's attributes via block context. Identify our
		// feed query by the parent's postType — only the rs-following-feed
		// query block queries rs_feed_item with inherit=false.
		$parent_post_type = $block->context['query']['postType'] ?? '';
		if ( 'rs_feed_item' !== $parent_post_type ) {
			return $query;
		}

		$tax_clauses = [];
		foreach ( [ 'rs_source', 'rs_feed_type', 'rs_feed_category' ] as $tax ) {
			$term_slug = get_query_var( $tax );
			if ( $term_slug ) {
				$tax_clauses[] = [
					'taxonomy' => $tax,
					'terms'    => [ $term_slug ],
					'field'    => 'slug',
				];
			}
		}

		if ( ! $tax_clauses ) {
			return $query;
		}

		$query['tax_query'] = empty( $query['tax_query'] )
			? $tax_clauses
			: array_merge( $query['tax_query'], $tax_clauses );

		return $query;
	}

	public static function force_feed_item_for_taxonomy_archives( WP_Query $query ): void {
		if ( ! $query->is_main_query() ) {
			return;
		}

		$our_taxonomies = [ 'rs_source', 'rs_feed_type', 'rs_feed_category' ];
		foreach ( $our_taxonomies as $tax ) {
			if ( $query->get( $tax ) ) {
				$query->set( 'post_type', 'rs_feed_item' );
				return;
			}
		}
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
	 * (older than 15 minutes).
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
		self::queue_refresh();
	}

	public static function refresh_secret(): string {
		return wp_hash( 'rs_feed_refresh_' . wp_salt() );
	}

	/**
	 * Queue a refresh.
	 *
	 * @param bool $force When true (user-initiated, e.g. the Refresh button),
	 *                    a stale `queued` lock is treated as a zombie and
	 *                    cleared so the request always proceeds. A `running`
	 *                    lock is always respected — we never disturb an
	 *                    in-flight fetch. When false (passive/visit-triggered),
	 *                    any existing lock blocks new queueing.
	 * @return bool True if a refresh was newly queued or re-queued.
	 */
	public static function queue_refresh( bool $force = false ): bool {
		$lock = get_transient( self::REFRESH_LOCK );

		// An active fetch is in progress — never queue another one on top of it.
		if ( self::REFRESH_LOCK_RUNNING === $lock ) {
			return false;
		}

		// Passive refresh: any lock blocks; user-initiated bulldozes a stuck queue.
		if ( $lock && ! $force ) {
			return false;
		}

		// Forcing past a stuck queued lock — wipe both the lock and any scheduled
		// event so we requeue cleanly. spawn_cron() below nudges wp-cron to
		// actually fire (helps in environments where cron is flaky, e.g. docker).
		if ( $force && self::REFRESH_LOCK_QUEUED === $lock ) {
			delete_transient( self::REFRESH_LOCK );
			wp_clear_scheduled_hook( self::REFRESH_HOOK );
		}

		$scheduled = wp_next_scheduled( self::REFRESH_HOOK );
		if ( ! $scheduled ) {
			$scheduled = wp_schedule_single_event( time(), self::REFRESH_HOOK );
		}

		if ( false === $scheduled ) {
			return false;
		}

		set_transient( self::REFRESH_LOCK, self::REFRESH_LOCK_QUEUED, self::REFRESH_LOCK_TTL );
		spawn_cron();

		// spawn_cron() is a no-op when DISABLE_WP_CRON is true (common on
		// shared hosting — Hostinger, SiteGround, etc. all set it). In that
		// case, fire a direct non-blocking POST to wp-cron.php ourselves so
		// the user-initiated refresh actually runs. wp-cron.php itself does
		// not honour the constant; only spawn_cron() does.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			wp_remote_post( site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) ), [
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			] );
		}

		return true;
	}

	// ── Frontend ──────────────────────────────────────────────────────────────

	public static function enqueue_infinite_scroll(): void {
		if ( ! self::is_feed_archive_view() ) {
			return;
		}

		$plugin_url = plugin_dir_url( dirname( dirname( __DIR__ ) ) . '/radical-socials.php' );

		if ( is_post_type_archive( [ 'rs_feed_item', 'rs_favorite' ] ) ) {
			wp_enqueue_script_module(
				'radical-socials/following',
				$plugin_url . 'modules/following/assets/infinite-scroll.js',
				[ '@wordpress/interactivity' ],
				filemtime( __DIR__ . '/assets/infinite-scroll.js' ) ?: '1'
			);
		}

		wp_enqueue_style(
			'radical-socials/following',
			$plugin_url . 'modules/following/assets/following.css',
			[],
			filemtime( __DIR__ . '/assets/following.css' ) ?: '1'
		);
	}

	private static function is_feed_archive_view(): bool {
		if ( is_post_type_archive( [ 'rs_feed_item', 'rs_favorite' ] ) ) {
			return true;
		}

		if ( is_tax( [ 'rs_source', 'rs_feed_type', 'rs_feed_category' ] ) ) {
			return true;
		}

		foreach ( [ 'rs_source', 'rs_feed_type', 'rs_feed_category' ] as $tax ) {
			if ( get_query_var( $tax ) ) {
				return true;
			}
		}

		return false;
	}

	/** Option name for the "let logged-out visitors see the following page" toggle. */
	const PUBLIC_OPTION = 'rs_following_public';

	/**
	 * Whether the current viewer is allowed to see the following feed and its
	 * items. Logged-in users always can; logged-out visitors only when the
	 * site owner opted in.
	 */
	public static function can_view_following(): bool {
		return is_user_logged_in() || (bool) get_option( self::PUBLIC_OPTION, false );
	}

	/**
	 * 404 every URL that surfaces rs_feed_item or rs_favorite content for
	 * logged-out visitors when the public-following option is off.
	 *
	 * Delegates to is_feed_archive_view() so /following/, /favorites/, any
	 * single view, real taxonomy archives, and home-page taxonomy filters
	 * (e.g. /?rs_feed_type=activitypub) are all covered by one source of
	 * truth — the same predicate enqueue_infinite_scroll() uses.
	 *
	 * Single feed-item and favorite views aren't covered by
	 * is_feed_archive_view(), so we add them explicitly here.
	 *
	 * Runs at priority 0 on template_redirect so it short-circuits before
	 * our own block filter and the visit-triggered refresh hook fire.
	 */
	public static function gate_following_access(): void {
		if ( self::can_view_following() ) {
			return;
		}

		if ( ! self::is_feed_archive_view() && ! is_singular( [ 'rs_feed_item', 'rs_favorite' ] ) ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Suppress core/post-title output for rs_feed_item posts when:
	 *   - the post is an ActivityPub item (notes have no titles), OR
	 *   - the title is empty (e.g. RSS items that came through with no title,
	 *     or older entries that had the now-removed "(untitled)" placeholder
	 *     wiped by the next refresh).
	 *
	 * Avoids any locale-specific string matching — we rely on the feed_type
	 * meta and on the presence of a real title rather than checking for a
	 * translated placeholder.
	 */
	public static function hide_empty_or_activitypub_titles( string $block_content, array $block ): string {
		$post_id = $block['context']['postId'] ?? get_the_ID();
		if ( ! $post_id || 'rs_feed_item' !== get_post_type( $post_id ) ) {
			return $block_content;
		}

		$feed_type = (string) get_post_meta( $post_id, '_rs_item_feed_type', true );
		if ( 'activitypub' === $feed_type ) {
			return '';
		}

		if ( '' === trim( (string) get_post_field( 'post_title', $post_id ) ) ) {
			return '';
		}

		return $block_content;
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
			'refreshUrl'           => rest_url( 'radical-socials/v1/following/refresh' ),
			'nonce'                => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'canRefresh'           => is_user_logged_in() && $is_following,
			'refreshing'           => false,
			'pulling'              => false,
			'refreshLabel'         => __( 'Refresh feed', 'radical-socials' ),
			'refreshingLabel'      => __( 'Refreshing...', 'radical-socials' ),
			'noNewPostsLabel'      => __( 'No new posts', 'radical-socials' ),
			'stillRefreshingLabel' => __( 'Still refreshing', 'radical-socials' ),
			'newPostLabel'         => __( '1 new post', 'radical-socials' ),
			'newPostsLabel'        => __( '%d new posts', 'radical-socials' ),
		] );

		$last_fetched = (int) get_option( 'rs_last_feed_fetch', 0 );
		$last_title   = $last_fetched ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_fetched ) : '';

		$pull_indicator = ( is_user_logged_in() && $is_following )
			? '<div class="rs-refresh-bar" data-wp-class--rs-pull-refreshing="state.refreshing">'
				. '<button class="rs-refresh-btn" data-wp-on--click="actions.refresh" data-wp-bind--disabled="state.refreshing">'
					. '<span class="rs-pull-arrow" aria-hidden="true">↻</span>'
					. '<span class="rs-pull-spinner" aria-hidden="true"></span>'
					. '<span class="rs-refresh-label">' . esc_html__( 'Refresh feed', 'radical-socials' ) . '</span>'
				. '</button>'
				. '<span class="rs-last-refreshed" data-rs-last-fetched="' . esc_attr( (string) $last_fetched ) . '" title="' . esc_attr( $last_title ) . '">'
					. esc_html( self::last_refreshed_label( $last_fetched ) )
				. '</span>'
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

	private static function last_refreshed_label( int $last_fetched ): string {
		if ( ! $last_fetched ) {
			return __( 'Not refreshed yet', 'radical-socials' );
		}

		$elapsed = time() - $last_fetched;
		if ( $elapsed < MINUTE_IN_SECONDS ) {
			return __( 'Last refreshed just now', 'radical-socials' );
		}

		return sprintf(
			/* translators: %s: Human-readable elapsed time, for example "8 minutes". */
			__( 'Last refreshed %s ago', 'radical-socials' ),
			human_time_diff( $last_fetched )
		);
	}

	// ── ActivityPub push ──────────────────────────────────────────────────────

	/**
	 * Called synchronously when the ActivityPub plugin saves a new inbox
	 * activity. Ingests it into the rs_feed_item CPT immediately.
	 */
	public static function on_activitypub_activity( int $post_id, WP_Post $post ): void {
		// Only process Create activities (new content, not likes/announces).
		$activity_type = get_post_meta( $post_id, '_activitypub_activity_type', true )
			?: get_post_meta( $post_id, '_ap_activity_type', true );
		if ( $activity_type && 'Create' !== $activity_type ) {
			return;
		}

		$items = Radical_Socials_ActivityPub_Fetcher::normalize_activity( $post_id );
		if ( $items ) {
			Radical_Socials_Feed_Fetcher::upsert_item( $items );
			Radical_Socials_Feed_Fetcher::enforce_cap();
			update_option( 'rs_last_feed_fetch', time(), false );
		}
	}
}

Radical_Socials_Following::init();
