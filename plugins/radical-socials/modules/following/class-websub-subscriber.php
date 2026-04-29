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

	const REST_NAMESPACE  = 'radical-socials/v1';
	const CALLBACK_ROUTE  = '/websub/callback';
	const BATCH_ROUTE     = '/websub/subscribe-batch';
	const SUBS_OPTION     = 'rs_websub_subscriptions'; // array of feed_url => hub_url
	const PENDING_OPTION  = 'rs_websub_pending';       // array of feed_urls awaiting subscription
	const BATCH_SIZE      = 10;

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

		// Browser-driven batch subscription endpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			self::BATCH_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_subscribe_batch' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'args'                => [
					'urls' => [
						'required' => true,
						'type'     => 'array',
						'items'    => [ 'type' => 'string', 'format' => 'uri' ],
					],
				],
			]
		);
	}

	/**
	 * Process a batch of subscription requests from the browser.
	 * Returns per-URL results so the progress bar can advance accurately.
	 */
	public static function handle_subscribe_batch( WP_REST_Request $request ): WP_REST_Response {
		$urls    = (array) $request->get_param( 'urls' );
		$results = [];

		foreach ( $urls as $url ) {
			$url = esc_url_raw( $url );
			if ( ! $url ) {
				continue;
			}
			self::subscribe( $url );
			$results[] = $url;
		}

		$pending = (array) get_option( self::PENDING_OPTION, [] );
		$pending = array_values( array_diff( $pending, $results ) );
		update_option( self::PENDING_OPTION, $pending, false );

		return new WP_REST_Response( [
			'processed' => $results,
			'remaining' => count( $pending ),
		], 200 );
	}

	/**
	 * Hub sends GET with hub.challenge to verify our subscription intent.
	 * We echo the challenge back to confirm.
	 */
	public static function handle_verification( WP_REST_Request $request ): WP_REST_Response {
		$mode      = $request->get_param( 'hub_mode' );
		$topic     = $request->get_param( 'hub_topic' );
		$challenge = $request->get_param( 'hub_challenge' );

		if ( ! $challenge ) {
			return new WP_REST_Response( 'missing_challenge', 400 );
		}

		if ( 'subscribe' === $mode ) {
			$subs = self::get_subscriptions();
			// Only confirm if we actually requested this subscription.
			if ( ! isset( $subs[ $topic ] ) ) {
				return new WP_REST_Response( 'unknown_topic', 404 );
			}
		}

		return new WP_REST_Response( $challenge, 200 );
	}

	/**
	 * Hub POSTs new feed content. Parse and ingest immediately.
	 */
	public static function handle_notification( WP_REST_Request $request ): WP_REST_Response {
		$body         = $request->get_body();
		$content_type = $request->get_content_type()['value'] ?? '';

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

			$thumbnail = '';
			$enclosure = $item->get_enclosure();
			if ( $enclosure && str_starts_with( (string) $enclosure->get_type(), 'image/' ) ) {
				$thumbnail = esc_url_raw( (string) $enclosure->get_link() );
			}

			Radical_Socials_Feed_Fetcher::upsert_item( [
				'title'         => wp_strip_all_tags( (string) $item->get_title() ),
				'url'           => $url,
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
	 * When the RSS feed URL list changes, subscribe to hubs for new feeds
	 * and unsubscribe from removed ones.
	 *
	 * @param mixed $old_value
	 * @param mixed $new_value
	 */
	public static function on_feeds_updated( $old_value, $new_value ): void {
		$old_urls = self::parse_urls( (string) $old_value );
		$new_urls = self::parse_urls( (string) $new_value );

		foreach ( array_diff( $new_urls, $old_urls ) as $url ) {
			self::subscribe( $url );
		}
		foreach ( array_diff( $old_urls, $new_urls ) as $url ) {
			self::unsubscribe( $url );
		}
	}

	/**
	 * Discover hub URL for a feed and POST a subscribe request.
	 */
	public static function subscribe( string $feed_url ): void {
		$hub_url = self::discover_hub( $feed_url );
		if ( ! $hub_url ) {
			return; // no hub; on-demand fetch will handle this feed
		}

		$callback = rest_url( self::REST_NAMESPACE . self::CALLBACK_ROUTE );

		wp_remote_post(
			$hub_url,
			[
				'body' => [
					'hub.callback'      => add_query_arg( 'hub_topic', rawurlencode( $feed_url ), $callback ),
					'hub.mode'          => 'subscribe',
					'hub.topic'         => $feed_url,
					'hub.lease_seconds' => 864000, // 10 days; hub may override
				],
				'timeout' => 10,
			]
		);

		$subs               = self::get_subscriptions();
		$subs[ $feed_url ]  = $hub_url;
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

		$hub_url  = $subs[ $feed_url ];
		$callback = rest_url( self::REST_NAMESPACE . self::CALLBACK_ROUTE );

		wp_remote_post(
			$hub_url,
			[
				'body' => [
					'hub.callback' => add_query_arg( 'hub_topic', rawurlencode( $feed_url ), $callback ),
					'hub.mode'     => 'unsubscribe',
					'hub.topic'    => $feed_url,
				],
				'timeout' => 10,
			]
		);

		unset( $subs[ $feed_url ] );
		update_option( self::SUBS_OPTION, $subs, false );
	}

	/**
	 * Fetch the feed and look for a <link rel="hub"> header or element.
	 */
	private static function discover_hub( string $feed_url ): string {
		// First check HTTP Link headers (faster).
		$response = wp_remote_head( $feed_url, [ 'timeout' => 8 ] );
		if ( ! is_wp_error( $response ) ) {
			$link_header = wp_remote_retrieve_header( $response, 'link' );
			if ( $link_header ) {
				foreach ( (array) $link_header as $header ) {
					if ( preg_match( '/<([^>]+)>;\s*rel=["\']?hub["\']?/i', $header, $m ) ) {
						return esc_url_raw( $m[1] );
					}
				}
			}
		}

		// Fall back to parsing the feed body for <atom:link rel="hub">.
		$response = wp_remote_get( $feed_url, [ 'timeout' => 10 ] );
		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		if ( preg_match( '/<(?:atom:)?link[^>]+rel=["\']hub["\'][^>]+href=["\']([^"\']+)["\']/', $body, $m ) ||
		     preg_match( '/<(?:atom:)?link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']hub["\']/', $body, $m ) ) {
			return esc_url_raw( $m[1] );
		}

		return '';
	}

	/** @return array<string, string> feed_url => hub_url */
	private static function get_subscriptions(): array {
		return (array) get_option( self::SUBS_OPTION, [] );
	}

	/** @return string[] */
	private static function parse_urls( string $raw ): array {
		return array_values( array_filter(
			array_map( 'trim', explode( "\n", $raw ) ),
			'wp_http_validate_url'
		) );
	}
}

Radical_Socials_WebSub_Subscriber::init();
