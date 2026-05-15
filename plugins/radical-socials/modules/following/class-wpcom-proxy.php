<?php
/**
 * WP.com OAuth Proxy
 *
 * Runs only when this site is configured as the broker (RS_WPCOM_PROXY_MODE).
 * Brokers OAuth handshakes between consumer Radical Socials installs and
 * WordPress.com — consumer sites never see the WP.com client_secret.
 *
 * Why this exists:
 *   WordPress.com's OAuth requires each app's redirect_uri to be in a
 *   pre-registered allowlist. Shipping a shared client_id+secret in the
 *   plugin wouldn't work because every install runs on a different domain.
 *   This proxy is the single allowed redirect_uri; it forwards the callback
 *   to the originating site after validating a server-stored state record.
 *
 * Three endpoints (all under /wp-json/radical-socials/v1/wpcom-proxy/):
 *
 *   POST /init      — register an origin callback for an opaque state. Body:
 *                     { state, origin }. Response: { authorize_url }. The
 *                     consumer redirects the user to that URL next.
 *
 *   GET  /callback  — receives WP.com's redirect (?code=…&state=…). Looks up
 *                     the origin recorded at /init, redirects the user there
 *                     with the same code+state. Open-redirect-safe because
 *                     the origin is server-stored, not parsed from a query.
 *
 *   POST /exchange  — consumer POSTs { state, code }; we swap the code for an
 *                     access token using the secret we hold and return it.
 *                     State record is deleted on success (single-use).
 *
 * Storage: short-lived transients keyed by state. 10-minute TTL.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_WPCOM_Proxy {

	const REST_NAMESPACE = 'radical-socials/v1';
	const ROUTE_BASE     = '/wpcom-proxy';
	const STATE_TTL      = 600; // 10 minutes.
	const STATE_PREFIX   = 'rs_wpcom_proxy_state_';
	const AUTHORIZE_URL  = 'https://public-api.wordpress.com/oauth2/authorize';
	const TOKEN_URL      = 'https://public-api.wordpress.com/oauth2/token';

	public static function init(): void {
		// Defer the proxy-mode check to rest_api_init so the constants only
		// need to be defined by the time REST routes register (e.g. in
		// wp-config.php — which is read before rest_api_init fires but might
		// be read after this file loads in some bootstrap orders).
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function is_proxy_mode(): bool {
		return defined( 'RS_WPCOM_PROXY_MODE' ) && RS_WPCOM_PROXY_MODE
			&& defined( 'RS_WPCOM_CLIENT_ID' )    && RS_WPCOM_CLIENT_ID
			&& defined( 'RS_WPCOM_CLIENT_SECRET' ) && RS_WPCOM_CLIENT_SECRET;
	}

	public static function register_routes(): void {
		if ( ! self::is_proxy_mode() ) {
			return;
		}
		register_rest_route( self::REST_NAMESPACE, self::ROUTE_BASE . '/init', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_init' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'state'  => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'origin' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, self::ROUTE_BASE . '/callback', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_callback' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::REST_NAMESPACE, self::ROUTE_BASE . '/exchange', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_exchange' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'state' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'code'  => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
	}

	/**
	 * POST /init: record { state → origin } and return the WP.com authorize
	 * URL the consumer should redirect the user to.
	 */
	public static function handle_init( WP_REST_Request $request ): WP_REST_Response {
		$state  = (string) $request->get_param( 'state' );
		$origin = esc_url_raw( (string) $request->get_param( 'origin' ) );

		if ( ! self::looks_like_state( $state ) ) {
			return new WP_REST_Response( [ 'error' => 'bad_state' ], 400 );
		}
		if ( ! self::looks_like_https_origin( $origin ) ) {
			return new WP_REST_Response( [ 'error' => 'bad_origin' ], 400 );
		}

		set_transient( self::STATE_PREFIX . $state, $origin, self::STATE_TTL );

		$authorize_url = add_query_arg( [
			'client_id'     => RS_WPCOM_CLIENT_ID,
			'redirect_uri'  => self::our_callback_url(),
			'response_type' => 'code',
			'scope'         => 'global',
			'state'         => $state,
		], self::AUTHORIZE_URL );

		return new WP_REST_Response( [ 'authorize_url' => $authorize_url ], 200 );
	}

	/**
	 * GET /callback: WP.com sends the user here after authorization. We
	 * look up the consumer's origin (stored at /init) and bounce the user
	 * back there, carrying the code + state untouched.
	 */
	public static function handle_callback( WP_REST_Request $request ): void {
		$state = (string) $request->get_param( 'state' );
		$code  = (string) $request->get_param( 'code' );
		$err   = (string) $request->get_param( 'error' );

		if ( ! self::looks_like_state( $state ) ) {
			wp_die( esc_html__( 'Bad OAuth state', 'radical-socials' ), '', [ 'response' => 400 ] );
		}

		$origin = get_transient( self::STATE_PREFIX . $state );
		if ( ! $origin ) {
			wp_die( esc_html__( 'Unknown or expired OAuth state — start the connection again from your site.', 'radical-socials' ), '', [ 'response' => 400 ] );
		}

		// Pass through WP.com errors (user denied, etc.) so the consumer can
		// show a useful message rather than a generic "missing code".
		$args = [ 'state' => $state ];
		if ( $err ) {
			$args['error'] = $err;
		} elseif ( $code ) {
			$args['code'] = $code;
		}

		wp_safe_redirect( add_query_arg( $args, $origin ) );
		exit;
	}

	/**
	 * POST /exchange: consumer trades the code (delivered via the callback
	 * redirect) for an access token. We use the client_secret here — the
	 * consumer never sees it. State record is deleted on success.
	 */
	public static function handle_exchange( WP_REST_Request $request ): WP_REST_Response {
		$state = (string) $request->get_param( 'state' );
		$code  = (string) $request->get_param( 'code' );

		$origin = get_transient( self::STATE_PREFIX . $state );
		if ( ! $origin ) {
			return new WP_REST_Response( [ 'error' => 'state_unknown_or_used' ], 400 );
		}

		$response = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 15,
			'body'    => [
				'client_id'     => RS_WPCOM_CLIENT_ID,
				'client_secret' => RS_WPCOM_CLIENT_SECRET,
				'code'          => $code,
				'redirect_uri'  => self::our_callback_url(),
				'grant_type'    => 'authorization_code',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response( [ 'error' => 'token_exchange_network', 'detail' => $response->get_error_message() ], 502 );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code_http = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code_http || empty( $body['access_token'] ) ) {
			return new WP_REST_Response( [
				'error'    => 'token_exchange_failed',
				'wpcom'    => $body,
				'wpcom_status' => $code_http,
			], 502 );
		}

		// Single-use: a stolen state can't be replayed.
		delete_transient( self::STATE_PREFIX . $state );

		return new WP_REST_Response( [
			'access_token' => sanitize_text_field( $body['access_token'] ),
			'token_type'   => sanitize_text_field( $body['token_type'] ?? 'bearer' ),
			'blog_id'      => isset( $body['blog_id'] ) ? (int) $body['blog_id'] : null,
		], 200 );
	}

	private static function our_callback_url(): string {
		return rest_url( self::REST_NAMESPACE . self::ROUTE_BASE . '/callback' );
	}

	/** Defensive shape check on opaque tokens — UUIDs and similar. */
	private static function looks_like_state( string $s ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $s );
	}

	/** Origin must be HTTPS (or http://localhost for dev tests). */
	private static function looks_like_https_origin( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		$parsed = wp_parse_url( $url );
		if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return false;
		}
		$scheme = strtolower( $parsed['scheme'] );
		$host   = strtolower( $parsed['host'] );
		if ( 'https' === $scheme ) {
			return true;
		}
		// Allow http for localhost only — dev convenience.
		return 'http' === $scheme && ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || '127.0.0.1' === $host );
	}
}

Radical_Socials_WPCOM_Proxy::init();
