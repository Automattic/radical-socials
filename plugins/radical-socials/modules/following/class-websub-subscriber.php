<?php
/**
 * WebSub Subscriber
 *
 * Implements the WebSub (W3C, formerly PubSubHubbub) subscriber role.
 * When an RSS/Atom feed URL is added to settings, we discover its hub URL
 * and POST a subscription request. The hub then pushes new content to our
 * callback endpoint whenever the feed updates — no polling required.
 *
 * Feeds that don't advertise a hub fall back to on-demand fetch on page visit.
 *
 * Spec: https://www.w3.org/TR/websub/
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_WebSub_Subscriber {

	const REST_NAMESPACE = 'radical-socials/v1';
	const CALLBACK_ROUTE = '/websub/callback';
	const SUBS_OPTION    = 'rs_websub_subscriptions'; // array of feed_url => [ hub, secret ]

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::CALLBACK_ROUTE,
			[
				// GET: hub verification challenge
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'handle_verification' ],
					'permission_callback' => '__return_true',
				],
				// POST: hub pushes new content
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'handle_notification' ],
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	/**
	 * Hub sends GET with hub.challenge to verify our subscription intent.
	 * We echo the challenge back to confirm.
	 */
	public static function handle_verification( WP_REST_Request $request ): WP_REST_Response {
		$mode      = self::get_hub_param( $request, 'hub.mode' );
		$topic     = self::get_hub_param( $request, 'hub.topic' );
		$challenge = self::get_hub_param( $request, 'hub.challenge' );

		if ( '' === $challenge ) {
			return new WP_REST_Response( 'missing_challenge', 400 );
		}

		$subs = self::get_subscriptions();
		// Only confirm intents we actually requested.
		if ( ! isset( $subs[ $topic ] ) ) {
			return new WP_REST_Response( 'unknown_topic', 404 );
		}

		return new WP_REST_Response( $challenge, 200 );
	}

	/**
	 * Hub POSTs new feed content. Verify HMAC signature, then parse and ingest.
	 */
	public static function handle_notification( WP_REST_Request $request ): WP_REST_Response {
		$topic = self::get_hub_param( $request, 'hub.topic' );
		$body  = $request->get_body();

		if ( strlen( $body ) > 2 * MB_IN_BYTES ) {
			return new WP_REST_Response( 'payload_too_large', 200 ); // 200 so hub stops retrying
		}

		$subs = self::get_subscriptions();

		// Reject notifications for unknown or missing topics.
		if ( ! $topic || ! isset( $subs[ $topic ] ) ) {
			return new WP_REST_Response( 'unknown_topic', 200 );
		}

		// Verify HMAC-SHA256 signature when we have a shared secret.
		if ( isset( $subs[ $topic ]['secret'] ) ) {
			$secret    = $subs[ $topic ]['secret'];
			$signature = $request->get_header( 'x_hub_signature' );

			if ( ! $signature ) {
				return new WP_REST_Response( 'missing_signature', 200 );
			}

			[ $algo, $provided_hash ] = explode( '=', $signature, 2 ) + [ '', '' ];
			$expected_hash = hash_hmac( 'sha256', $body, $secret );

			if ( 'sha256' !== $algo || ! hash_equals( $expected_hash, $provided_hash ) ) {
				return new WP_REST_Response( 'invalid_signature', 200 );
			}
		}

		// Use SimplePie to parse the pushed Atom/RSS fragment.
		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/feed.php';
		}

		$feed = new SimplePie();
		$feed->set_raw_data( $body );
		$feed->set_cache_location( get_temp_dir() );
		$feed->init();

		$items = $feed->get_items();
		if ( empty( $items ) ) {
			return new WP_REST_Response( 'ok', 200 );
		}

		$channel_title = wp_strip_all_tags( (string) $feed->get_title() );
		$channel_url   = esc_url_raw( (string) $feed->get_permalink() );

		foreach ( $items as $item ) {
			$url = esc_url_raw( (string) $item->get_permalink() );
			if ( ! $url ) {
				continue;
			}

			$raw_content = (string) ( $item->get_content() ?: $item->get_description() );

			$thumbnail = '';
			$enclosure = $item->get_enclosure();
			if ( $enclosure && str_starts_with( (string) $enclosure->get_type(), 'image/' ) ) {
				$thumbnail = esc_url_raw( (string) $enclosure->get_link() );
			}
			if ( ! $thumbnail && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $raw_content, $m ) ) {
				$thumbnail = esc_url_raw( $m[1] );
			}

			Radical_Socials_Feed_Fetcher::upsert_item( [
				'title'         => wp_strip_all_tags( (string) $item->get_title() ),
				'url'           => $url,
				'content'       => $raw_content,
				'excerpt'       => wp_trim_words( wp_strip_all_tags( (string) $item->get_description() ), 30 ),
				'date'          => $item->get_date( 'c' ) ?: current_time( 'c' ),
				'source_name'   => $channel_title ?: parse_url( $channel_url, PHP_URL_HOST ),
				'source_url'    => $channel_url,
				'thumbnail_url' => $thumbnail,
				'guid'          => md5( $url ),
				'feed_type'     => 'rss',
			] );
		}

		Radical_Socials_Feed_Fetcher::enforce_cap();

		return new WP_REST_Response( 'ok', 200 );
	}

	/**
	 * Discover hub URL for a feed and POST a subscribe request.
	 */
	public static function subscribe( string $feed_url ): void {
		$hub_url = self::discover_hub( $feed_url );
		if ( ! $hub_url || ! Radical_Socials_RSS_Fetcher::is_safe_remote_url( $hub_url ) ) {
			return; // no hub; on-demand fetch will handle this feed
		}

		$secret   = wp_generate_password( 32, false );
		$callback = self::callback_url( $feed_url );

		wp_remote_post(
			$hub_url,
			[
				'body' => [
					'hub.callback'      => $callback,
					'hub.mode'          => 'subscribe',
					'hub.topic'         => $feed_url,
					'hub.secret'        => $secret,
					'hub.lease_seconds' => 864000, // 10 days; hub may override
				],
				'timeout'            => Radical_Socials_RSS_Fetcher::HTTP_TIMEOUT,
				'redirection'        => Radical_Socials_RSS_Fetcher::HTTP_REDIRECTION,
				'reject_unsafe_urls' => true,
			]
		);

		$subs             = self::get_subscriptions();
		$subs[ $feed_url ] = [ 'hub' => $hub_url, 'secret' => $secret ];
		update_option( self::SUBS_OPTION, $subs, false );
	}

	/**
	 * POST an unsubscribe request to the hub.
	 */
	public static function unsubscribe( string $feed_url ): void {
		$subs = self::get_subscriptions();
		if ( ! isset( $subs[ $feed_url ] ) ) {
			return;
		}

		$entry    = $subs[ $feed_url ];
		$hub_url  = is_array( $entry ) ? $entry['hub'] : $entry; // back-compat with old string format
		$callback = self::callback_url( $feed_url );

		if ( Radical_Socials_RSS_Fetcher::is_safe_remote_url( $hub_url ) ) {
			wp_remote_post(
				$hub_url,
				[
					'body' => [
						'hub.callback' => $callback,
						'hub.mode'     => 'unsubscribe',
						'hub.topic'    => $feed_url,
					],
					'timeout'            => Radical_Socials_RSS_Fetcher::HTTP_TIMEOUT,
					'redirection'        => Radical_Socials_RSS_Fetcher::HTTP_REDIRECTION,
					'reject_unsafe_urls' => true,
				]
			);
		}

		unset( $subs[ $feed_url ] );
		update_option( self::SUBS_OPTION, $subs, false );
	}

	/**
	 * Fetch the feed and look for a <link rel="hub"> header or element.
	 */
	private static function discover_hub( string $feed_url ): string {
		if ( ! Radical_Socials_RSS_Fetcher::is_safe_remote_url( $feed_url ) ) {
			return '';
		}

		// First check HTTP Link headers (faster).
		$response = wp_safe_remote_head( $feed_url, Radical_Socials_RSS_Fetcher::http_args() );
		if ( ! is_wp_error( $response ) ) {
			$link_header = wp_remote_retrieve_header( $response, 'link' );
			if ( $link_header ) {
				foreach ( (array) $link_header as $header ) {
					if ( preg_match( '/<([^>]+)>;\s*rel=["\']?hub["\']?/i', $header, $m ) ) {
						$hub_url = esc_url_raw( $m[1] );
						return Radical_Socials_RSS_Fetcher::is_safe_remote_url( $hub_url ) ? $hub_url : '';
					}
				}
			}
		}

		// Fall back to parsing the feed body for <atom:link rel="hub">.
		$response = wp_safe_remote_get( $feed_url, Radical_Socials_RSS_Fetcher::http_args() );
		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		if ( preg_match( '/<(?:atom:)?link[^>]+rel=["\']hub["\'][^>]+href=["\']([^"\']+)["\']/', $body, $m ) ||
		     preg_match( '/<(?:atom:)?link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']hub["\']/', $body, $m ) ) {
			$hub_url = esc_url_raw( $m[1] );
			return Radical_Socials_RSS_Fetcher::is_safe_remote_url( $hub_url ) ? $hub_url : '';
		}

		return '';
	}

	/** @return array<string, array{hub:string,secret:string}|string> */
	private static function get_subscriptions(): array {
		return (array) get_option( self::SUBS_OPTION, [] );
	}

	private static function get_hub_param( WP_REST_Request $request, string $name ): string {
		$value = $request->get_param( $name );

		if ( null === $value ) {
			$value = $request->get_param( str_replace( '.', '_', $name ) );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function callback_url( string $feed_url ): string {
		return add_query_arg(
			'hub.topic',
			$feed_url,
			rest_url( self::REST_NAMESPACE . self::CALLBACK_ROUTE )
		);
	}
}

Radical_Socials_WebSub_Subscriber::init();
