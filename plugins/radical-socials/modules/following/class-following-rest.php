<?php
/**
 * Following REST API
 *
 * Exposes three endpoints for the settings-page following table:
 *
 *  GET    /radical-socials/v1/following         — unified list (RSS + AP + WP.com)
 *  POST   /radical-socials/v1/following         — add one item (auto-detects type)
 *  DELETE /radical-socials/v1/following         — remove one item
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Following_REST {

	const REST_NAMESPACE    = 'radical-socials/v1';
	const ROUTE             = '/following';
	const FAVORITES_OPTION  = 'rs_following_favorites';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		$auth = fn() => current_user_can( 'manage_options' );

		register_rest_route( self::REST_NAMESPACE, self::ROUTE . '/refresh', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_refresh' ],
			'permission_callback' => [ __CLASS__, 'verify_refresh_secret' ],
		] );

		register_rest_route( self::REST_NAMESPACE, self::ROUTE . '/favorite', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'set_favorite' ],
			'permission_callback' => $auth,
			'args'                => [
				'type'    => [ 'required' => true, 'type' => 'string', 'enum' => [ 'rss', 'activitypub', 'wpcom' ] ],
				'id'      => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'starred' => [ 'required' => true, 'type' => 'boolean' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, self::ROUTE . '/opml/parse', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_opml_parse' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::REST_NAMESPACE, self::ROUTE . '/opml/entry', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_opml_entry' ],
			'permission_callback' => $auth,
			'args'                => [
				'url'        => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'title'      => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				'source_url' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				'categories' => [ 'type' => 'array',  'default' => [], 'items' => [ 'type' => 'string' ] ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, self::ROUTE . '/opml/export', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_opml_export' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::REST_NAMESPACE, self::ROUTE . '/import-from-account', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_import_from_account' ],
			'permission_callback' => $auth,
			'args'                => [
				'handle' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, self::ROUTE, [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'list_following' ],
				'permission_callback' => $auth,
			],
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'add_following' ],
				'permission_callback' => $auth,
				'args'                => [
					'input' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'type'  => [ 'required' => false, 'type' => 'string', 'enum' => [ 'activitypub', 'rss', '' ], 'default' => '' ],
				],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ __CLASS__, 'remove_following' ],
				'permission_callback' => $auth,
				'args'                => [
					'type' => [ 'required' => true, 'type' => 'string', 'enum' => [ 'rss', 'activitypub', 'wpcom' ] ],
					'id'   => [ 'required' => true, 'type' => 'string' ],
					'url'  => [ 'type' => 'string', 'default' => '' ],
				],
			],
		] );
	}

	// ── Background refresh ────────────────────────────────────────────────────

	public static function verify_refresh_secret( WP_REST_Request $request ): bool {
		// Logged-in users (site owner viewing their own feed) may trigger a refresh.
		if ( is_user_logged_in() ) {
			return true;
		}
		// Server-to-server background call authenticated by shared secret.
		$provided = $request->get_header( 'x_rs_refresh_secret' );
		return $provided && hash_equals( Radical_Socials_Following::refresh_secret(), $provided );
	}

	public static function handle_refresh(): WP_REST_Response {
		// Reset staleness state so the next page load triggers maybe_refresh_feed()
		// immediately, regardless of the 15-minute window.
		update_option( 'rs_last_feed_fetch', 0, false );
		delete_transient( 'rs_feed_refresh_lock' );

		// Schedule and attempt a non-blocking cron fire. On standard hosting this
		// runs the fetch immediately in a separate process. On Docker/restricted
		// environments spawn_cron() fails silently — the fetch will run on the
		// next page load via maybe_refresh_feed().
		wp_clear_scheduled_hook( 'rs_fetch_following' );
		wp_schedule_single_event( time() - 1, 'rs_fetch_following' );
		spawn_cron();

		return new WP_REST_Response( [ 'ok' => true ], 202 );
	}

	// ── OPML import / export ─────────────────────────────────────────────────

	/**
	 * Parse an uploaded OPML file and return the feed list as JSON without
	 * writing anything to the database. The client processes entries individually
	 * via handle_opml_entry() so it can show per-feed progress.
	 */
	public static function handle_opml_parse( WP_REST_Request $request ): WP_REST_Response {
		$files = $request->get_file_params();

		if ( empty( $files['file']['tmp_name'] ) ) {
			return new WP_REST_Response( [ 'error' => 'no_file' ], 400 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$xml = file_get_contents( $files['file']['tmp_name'] );
		if ( ! $xml ) {
			return new WP_REST_Response( [ 'error' => 'empty_file' ], 400 );
		}

		$feeds = Radical_Socials_OPML::parse( $xml );
		if ( empty( $feeds ) ) {
			return new WP_REST_Response( [ 'error' => 'no_feeds_found' ], 422 );
		}

		return new WP_REST_Response( [ 'feeds' => $feeds ], 200 );
	}

	/**
	 * Upsert a single feed entry from an OPML import.
	 * Returns 201 (added), 200 (updated), or 304 (no change).
	 */
	public static function handle_opml_entry( WP_REST_Request $request ): WP_REST_Response {
		$result = Radical_Socials_OPML::import( [ [
			'url'        => $request->get_param( 'url' ),
			'title'      => $request->get_param( 'title' ),
			'source_url' => $request->get_param( 'source_url' ),
			'categories' => (array) $request->get_param( 'categories' ),
		] ] );

		if ( $result['added'] > 0 ) {
			return new WP_REST_Response( $result, 201 );
		}
		if ( $result['updated'] > 0 ) {
			return new WP_REST_Response( $result, 200 );
		}
		return new WP_REST_Response( $result, 409 );
	}

	public static function handle_opml_export(): void {
		$xml      = Radical_Socials_OPML::export();
		$filename = sanitize_file_name( get_bloginfo( 'name' ) . '-subscriptions.opml' );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $xml ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $xml;
		exit;
	}

	// ── Import from account ───────────────────────────────────────────────────

	/**
	 * Fetch the ActivityPub following list for a given handle and return the
	 * actor URLs so the client can add them via the normal batch-add flow.
	 */
	public static function handle_import_from_account( WP_REST_Request $request ): WP_REST_Response {
		$handle = trim( $request->get_param( 'handle' ) );

		// Resolve handle or URL to an actor URL.
		if ( filter_var( $handle, FILTER_VALIDATE_URL ) ) {
			$actor_url = $handle;
		} elseif ( function_exists( 'Activitypub\Webfinger::resolve' ) || class_exists( 'Activitypub\Webfinger' ) ) {
			$actor_url = \Activitypub\Webfinger::resolve( $handle );
			if ( is_wp_error( $actor_url ) ) {
				return new WP_REST_Response( [ 'error' => 'account_not_found', 'message' => $actor_url->get_error_message() ], 404 );
			}
		} else {
			return new WP_REST_Response( [ 'error' => 'activitypub_unavailable' ], 503 );
		}

		$actor = self::fetch_ap_json( $actor_url );
		if ( ! $actor ) {
			return new WP_REST_Response( [ 'error' => 'account_not_found' ], 404 );
		}

		$following_url = $actor['following'] ?? null;
		if ( ! $following_url ) {
			return new WP_REST_Response( [ 'error' => 'no_following_url' ], 422 );
		}

		$actors = self::fetch_ap_collection( $following_url, 5 );

		if ( $actors === null ) {
			return new WP_REST_Response( [ 'error' => 'following_list_private' ], 403 );
		}

		// Each item is either a URL string or an actor object — normalise to URL strings.
		$actor_urls = array_values( array_filter( array_map(
			fn( $item ) => is_array( $item ) ? ( $item['id'] ?? null ) : ( is_string( $item ) ? $item : null ),
			$actors
		) ) );

		return new WP_REST_Response( [ 'actors' => $actor_urls, 'total' => count( $actor_urls ) ], 200 );
	}

	private static function fetch_ap_json( string $url ): ?array {
		$response = wp_remote_get( $url, [
			'timeout' => 10,
			'headers' => [ 'Accept' => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"' ],
		] );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Fetch items from an ActivityPub ordered collection, following `next` links
	 * up to $max_pages pages. Returns null if the collection is private (has
	 * totalItems but no readable items).
	 *
	 * @return array<int,mixed>|null
	 */
	private static function fetch_ap_collection( string $url, int $max_pages = 5 ): ?array {
		$collection = self::fetch_ap_json( $url );
		if ( ! $collection ) {
			return null;
		}

		// Some instances return items directly on the collection object.
		if ( ! empty( $collection['orderedItems'] ) ) {
			return $collection['orderedItems'];
		}

		// Private following lists: totalItems present but no first/items.
		if ( isset( $collection['totalItems'] ) && empty( $collection['first'] ) ) {
			return null;
		}

		$first = $collection['first'] ?? null;
		if ( ! $first ) {
			return [];
		}

		$next  = is_array( $first ) ? ( $first['id'] ?? null ) : $first;
		$items = [];
		$page  = 0;

		while ( $next && $page < $max_pages ) {
			$pg = self::fetch_ap_json( $next );
			if ( ! $pg ) {
				break;
			}
			$items = array_merge( $items, $pg['orderedItems'] ?? [] );
			$next  = $pg['next'] ?? null;
			$page++;
		}

		return $items;
	}

	// ── GET ───────────────────────────────────────────────────────────────────

	public static function list_following(): WP_REST_Response {
		$favs  = (array) get_option( self::FAVORITES_OPTION, [] );
		$items = array_merge(
			array_reverse( self::list_rss() ),
			array_reverse( self::list_activitypub() ),
			array_reverse( self::list_wpcom() ),
		);
		foreach ( $items as &$item ) {
			$item['starred'] = in_array( $item['type'] . ':' . $item['id'], $favs, true );
		}
		unset( $item );
		return new WP_REST_Response( $items, 200 );
	}

	public static function set_favorite( WP_REST_Request $request ): WP_REST_Response {
		$key     = $request->get_param( 'type' ) . ':' . $request->get_param( 'id' );
		$starred = (bool) $request->get_param( 'starred' );
		$favs    = (array) get_option( self::FAVORITES_OPTION, [] );

		if ( $starred ) {
			if ( ! in_array( $key, $favs, true ) ) {
				$favs[] = $key;
			}
		} else {
			$favs = array_values( array_filter( $favs, fn( $f ) => $f !== $key ) );
		}

		update_option( self::FAVORITES_OPTION, $favs, false );
		return new WP_REST_Response( [ 'starred' => $starred ], 200 );
	}

	private static function list_rss(): array {
		$subs  = (array) get_option( 'rs_rss_subscriptions', [] );
		$items = [];
		foreach ( $subs as $sub ) {
			$items[] = [
				'id'         => md5( $sub['url'] ),
				'type'       => 'rss',
				'url'        => $sub['url'],
				'title'      => $sub['title'] ?: '',
				'source_url' => $sub['source_url'] ?? '',
				'categories' => $sub['categories'] ?? [],
			];
		}
		return $items;
	}

	private static function list_activitypub(): array {
		if ( ! class_exists( 'Activitypub\Collection\Following' ) ) {
			return [];
		}
		$uid     = get_current_user_id();
		$follows = \Activitypub\Collection\Following::query_all( $uid )['following'];
		$items   = [];
		foreach ( $follows as $post ) {
			$acct    = get_post_meta( $post->ID, '_activitypub_acct', true );
			$display = $acct ?: $post->post_title ?: $post->guid;
			$items[] = [
				'id'    => (string) $post->ID,
				'type'  => 'activitypub',
				'url'   => $post->guid,
				'title' => $display,
			];
		}
		return $items;
	}

	private static function list_wpcom(): array {
		if ( ! Radical_Socials_WPCOM_OAuth::is_connected() ) {
			return [];
		}
		return Radical_Socials_WPCOM_Reader::get_following_list();
	}

	// ── POST ──────────────────────────────────────────────────────────────────

	public static function add_following( WP_REST_Request $request ): WP_REST_Response {
		$input = trim( $request->get_param( 'input' ) );

		// Explicit type override — used by the import-from-account flow.
		if ( 'activitypub' === $request->get_param( 'type' ) ) {
			return self::add_activitypub( $input );
		}

		// @handle@instance or @handle format.
		if ( str_starts_with( $input, '@' ) ) {
			return self::add_activitypub( $input );
		}

		// Mastodon-style profile URL — checked on raw input before URL validation
		// because wp_http_validate_url may reject URLs with @ in the path.
		// Also handles cross-instance links: https://mastodon.social/@user@other.instance
		if ( preg_match( '~^https?://([^/]+)/@([^/?#]+)/?$~', $input, $m ) ) {
			$handle = str_contains( $m[2], '@' ) ? '@' . $m[2] : '@' . $m[2] . '@' . $m[1];
			return self::add_activitypub( $handle );
		}

		$url = esc_url_raw( $input );
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_REST_Response( [ 'error' => 'invalid_url', 'input' => $input ], 400 );
		}

		return self::add_rss( $url );
	}

	private static function add_rss( string $url ): WP_REST_Response {
		$subs = (array) get_option( 'rs_rss_subscriptions', [] );
		$urls = array_column( $subs, 'url' );

		// Resolve to canonical URL before storing (handles moved/http→https feeds).
		$resolved = Radical_Socials_RSS_Fetcher::resolve_url( $url );
		if ( in_array( $resolved, $urls, true ) ) {
			return new WP_REST_Response( [ 'error' => 'already_exists', 'input' => $url ], 409 );
		}

		// Fetch the feed now to get its title and seed initial items.
		$title      = $resolved;
		$source_url = '';
		$items      = Radical_Socials_RSS_Fetcher::fetch( $resolved, 20 );
		if ( ! empty( $items ) ) {
			$title      = $items[0]['source_name'] ?: $resolved;
			$source_url = $items[0]['source_url'] ?? '';
			foreach ( $items as $item ) {
				Radical_Socials_Feed_Fetcher::upsert_item( $item );
			}
			Radical_Socials_Feed_Fetcher::enforce_cap();
		}

		$subs[] = [ 'url' => $resolved, 'title' => $title, 'source_url' => $source_url ];
		update_option( 'rs_rss_subscriptions', $subs, false );

		// Try WebSub — fire-and-forget, failure is non-fatal.
		Radical_Socials_WebSub_Subscriber::subscribe( $resolved );

		return new WP_REST_Response( [
			'id'    => md5( $resolved ),
			'type'  => 'rss',
			'url'   => $resolved,
			'title' => $title,
		], 201 );
	}

	private static function add_activitypub( string $handle ): WP_REST_Response {
		if ( ! function_exists( 'Activitypub\follow' ) ) {
			return new WP_REST_Response( [ 'error' => 'activitypub_unavailable', 'input' => $handle ], 503 );
		}

		$uid    = get_current_user_id();
		$result = \Activitypub\follow( $handle, $uid );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [
				'error' => $result->get_error_code(),
				'input' => $handle,
			], 'activitypub_already_following' === $result->get_error_code() ? 409 : 400 );
		}

		// Remember which WP user owns ActivityPub follows so the outbox poller can find them.
		update_option( 'rs_ap_follow_user_id', $uid, false );

		return new WP_REST_Response( [
			'id'    => (string) $result,
			'type'  => 'activitypub',
			'url'   => $handle,
			'title' => $handle,
		], 201 );
	}

	// ── DELETE ────────────────────────────────────────────────────────────────

	public static function remove_following( WP_REST_Request $request ): WP_REST_Response {
		$type = $request->get_param( 'type' );
		$id   = $request->get_param( 'id' );
		$url  = $request->get_param( 'url' );

		switch ( $type ) {
			case 'rss':
				$subs        = (array) get_option( 'rs_rss_subscriptions', [] );
				$deleted_sub = null;
				$subs        = array_values(
					array_filter( $subs, function ( $s ) use ( $id, &$deleted_sub ) {
						if ( md5( $s['url'] ) === $id ) {
							$deleted_sub = $s;
							return false;
						}
						return true;
					} )
				);

				if ( ! $deleted_sub ) {
					return new WP_REST_Response( [ 'error' => 'rss_subscription_not_found' ], 404 );
				}

				update_option( 'rs_rss_subscriptions', $subs, false );
				Radical_Socials_WebSub_Subscriber::unsubscribe( $deleted_sub['url'] );
				self::delete_feed_items_for_source( $deleted_sub );

				// Remove from favorites.
				$fav_key = 'rss:' . md5( $deleted_sub['url'] );
				$favs    = array_values( array_filter(
					(array) get_option( self::FAVORITES_OPTION, [] ),
					fn( $f ) => $f !== $fav_key
				) );
				update_option( self::FAVORITES_OPTION, $favs, false );
				break;

			case 'activitypub':
				if ( ! $url ) {
					return new WP_REST_Response( [ 'error' => 'missing_activitypub_url' ], 400 );
				}

				if ( ! function_exists( 'Activitypub\unfollow' ) ) {
					return new WP_REST_Response( [ 'error' => 'activitypub_unavailable' ], 503 );
				}

				$result = \Activitypub\unfollow( $url, get_current_user_id() );
				if ( is_wp_error( $result ) ) {
					return new WP_REST_Response( [
						'error' => $result->get_error_code(),
						'input' => $url,
					], 400 );
				}

				if ( false === $result ) {
					return new WP_REST_Response( [ 'error' => 'activitypub_unfollow_failed' ], 502 );
				}

				// Remove from favorites.
				$fav_key = 'activitypub:' . $id;
				$favs    = array_values( array_filter(
					(array) get_option( self::FAVORITES_OPTION, [] ),
					fn( $f ) => $f !== $fav_key
				) );
				update_option( self::FAVORITES_OPTION, $favs, false );
				break;

			case 'wpcom':
				if ( ! $id ) {
					return new WP_REST_Response( [ 'error' => 'missing_blog_id' ], 400 );
				}

				if ( ! Radical_Socials_WPCOM_Reader::unfollow_site( $id ) ) {
					return new WP_REST_Response( [ 'error' => 'wpcom_unfollow_failed' ], 502 );
				}

				$fav_key = 'wpcom:' . $id;
				$favs    = array_values( array_filter(
					(array) get_option( self::FAVORITES_OPTION, [] ),
					fn( $f ) => $f !== $fav_key
				) );
				update_option( self::FAVORITES_OPTION, $favs, false );
				break;

			default:
				return new WP_REST_Response( [ 'error' => 'invalid_type' ], 400 );
		}

		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	/**
	 * Delete all rs_feed_item posts belonging to a removed RSS subscription.
	 * Matches by _rs_item_source_url meta (homepage URL) when available,
	 * otherwise falls back to the rs_source taxonomy term (feed title).
	 */
	private static function delete_feed_items_for_source( array $sub ): void {
		$args = [
			'post_type'      => 'rs_feed_item',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 9999,
		];

		if ( ! empty( $sub['source_url'] ) ) {
			$args['meta_query'] = [
				[
					'key'   => '_rs_item_source_url',
					'value' => $sub['source_url'],
				],
			];
		} elseif ( ! empty( $sub['title'] ) ) {
			$args['tax_query'] = [
				[
					'taxonomy' => 'rs_source',
					'field'    => 'name',
					'terms'    => $sub['title'],
				],
			];
		} else {
			return;
		}

		foreach ( get_posts( $args ) as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
}

Radical_Socials_Following_REST::init();
