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

	// ── GET ───────────────────────────────────────────────────────────────────

	public static function list_following(): WP_REST_Response {
		$favs  = (array) get_option( self::FAVORITES_OPTION, [] );
		$items = array_merge(
			self::list_rss(),
			self::list_activitypub(),
			self::list_wpcom(),
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
			];
		}
		return $items;
	}

	private static function list_activitypub(): array {
		if ( ! class_exists( 'Activitypub\Collection\Following' ) ) {
			return [];
		}
		$follows = \Activitypub\Collection\Following::get_many( 0 ); // blog actor = 0
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

		// @handle@instance or @handle format.
		if ( str_starts_with( $input, '@' ) ) {
			return self::add_activitypub( $input );
		}

		$url = esc_url_raw( $input );
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_REST_Response( [ 'error' => 'invalid_url', 'input' => $input ], 400 );
		}

		// Mastodon-style profile URL: https://instance.social/@handle
		if ( preg_match( '~^https?://([^/]+)/@([^/@][^/]*)/?$~', $url, $m ) ) {
			return self::add_activitypub( '@' . $m[2] . '@' . $m[1] );
		}

		return self::add_rss( $url );
	}

	private static function add_rss( string $url ): WP_REST_Response {
		$subs = (array) get_option( 'rs_rss_subscriptions', [] );
		$urls = array_column( $subs, 'url' );

		if ( in_array( $url, $urls, true ) ) {
			return new WP_REST_Response( [ 'error' => 'already_exists', 'input' => $url ], 409 );
		}

		// Resolve to canonical URL before storing (handles moved/http→https feeds).
		$resolved = Radical_Socials_RSS_Fetcher::resolve_url( $url );

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
		Radical_Socials_WebSub_Subscriber::subscribe( $url );

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

		$result = \Activitypub\follow( $handle, 0 ); // blog actor = 0

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [
				'error' => $result->get_error_code(),
				'input' => $handle,
			], 'activitypub_already_following' === $result->get_error_code() ? 409 : 400 );
		}

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
				update_option( 'rs_rss_subscriptions', $subs, false );
				if ( $url ) {
					Radical_Socials_WebSub_Subscriber::unsubscribe( $url );
				}
				if ( $deleted_sub ) {
					self::delete_feed_items_for_source( $deleted_sub );
				}
				// Remove from favorites.
				if ( $deleted_sub ) {
					$fav_key = 'rss:' . md5( $deleted_sub['url'] );
					$favs    = array_values( array_filter(
						(array) get_option( self::FAVORITES_OPTION, [] ),
						fn( $f ) => $f !== $fav_key
					) );
					update_option( self::FAVORITES_OPTION, $favs, false );
				}
				break;

			case 'activitypub':
				if ( function_exists( 'Activitypub\unfollow' ) && $url ) {
					\Activitypub\unfollow( $url, 0 );
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
				Radical_Socials_WPCOM_Reader::unfollow_site( $id );
				$fav_key = 'wpcom:' . $id;
				$favs    = array_values( array_filter(
					(array) get_option( self::FAVORITES_OPTION, [] ),
					fn( $f ) => $f !== $fav_key
				) );
				update_option( self::FAVORITES_OPTION, $favs, false );
				break;
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
