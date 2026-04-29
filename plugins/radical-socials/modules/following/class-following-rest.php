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

	const REST_NAMESPACE = 'radical-socials/v1';
	const ROUTE          = '/following';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		$auth = fn() => current_user_can( 'manage_options' );

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

	// ── GET ───────────────────────────────────────────────────────────────────

	public static function list_following(): WP_REST_Response {
		$items = array_merge(
			self::list_rss(),
			self::list_activitypub(),
			self::list_wpcom(),
		);
		return new WP_REST_Response( $items, 200 );
	}

	private static function list_rss(): array {
		$subs  = (array) get_option( 'rs_rss_subscriptions', [] );
		$items = [];
		foreach ( $subs as $sub ) {
			$items[] = [
				'id'    => md5( $sub['url'] ),
				'type'  => 'rss',
				'url'   => $sub['url'],
				'title' => $sub['title'] ?: $sub['url'],
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

		// Fetch the feed now to get its title and seed initial items.
		$title = $url;
		$items = Radical_Socials_RSS_Fetcher::fetch( $url, 20 );
		if ( ! empty( $items ) ) {
			$title = $items[0]['source_name'] ?: $url;
			foreach ( $items as $item ) {
				Radical_Socials_Feed_Fetcher::upsert_item( $item );
			}
			Radical_Socials_Feed_Fetcher::enforce_cap();
		}

		$subs[] = [ 'url' => $url, 'title' => $title ];
		update_option( 'rs_rss_subscriptions', $subs, false );

		// Try WebSub — fire-and-forget, failure is non-fatal.
		Radical_Socials_WebSub_Subscriber::subscribe( $url );

		return new WP_REST_Response( [
			'id'    => md5( $url ),
			'type'  => 'rss',
			'url'   => $url,
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
				$subs = (array) get_option( 'rs_rss_subscriptions', [] );
				$subs = array_values( array_filter( $subs, fn( $s ) => md5( $s['url'] ) !== $id ) );
				update_option( 'rs_rss_subscriptions', $subs, false );
				if ( $url ) {
					Radical_Socials_WebSub_Subscriber::unsubscribe( $url );
				}
				break;

			case 'activitypub':
				if ( function_exists( 'Activitypub\unfollow' ) && $url ) {
					\Activitypub\unfollow( $url, 0 );
				}
				break;

			case 'wpcom':
				Radical_Socials_WPCOM_Reader::unfollow_site( $id );
				break;
		}

		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}
}

Radical_Socials_Following_REST::init();
