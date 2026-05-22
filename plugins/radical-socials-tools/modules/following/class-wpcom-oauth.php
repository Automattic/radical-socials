<?php
/**
 * WP.com OAuth
 *
 * Handles the OAuth 2.0 authorization code flow against the WordPress.com API.
 * Stores the access token in wp_options so the feed fetcher can use it.
 *
 * Connect flow:
 *   1. Settings page shows "Connect WP.com Account" → links to connect_url().
 *   2. User authorizes on WordPress.com.
 *   3. WP.com redirects back to the REST callback route.
 *   4. Callback exchanges the code for a token and stores it.
 *   5. User is redirected to the settings page.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_WPCOM_OAuth {

	const TOKEN_OPTION   = 'rs_wpcom_access_token';
	const STATE_OPTION   = 'rs_wpcom_oauth_state';
	const AUTHORIZE_URL  = 'https://public-api.wordpress.com/oauth2/authorize';
	const TOKEN_URL      = 'https://public-api.wordpress.com/oauth2/token';
	const REST_NAMESPACE = 'radical-socials/v1';
	const CALLBACK_ROUTE = '/oauth/callback';

	/**
	 * Default WP.com OAuth broker used when an install doesn't define its
	 * own RS_WPCOM_CLIENT_ID/SECRET or RS_WPCOM_PROXY_URL. This is the
	 * Radical Socials project's hosted broker — it never stores tokens, it
	 * just brokers the authorize redirect + token-for-code swap so that
	 * shipping a single shared client_secret with the plugin isn't needed.
	 * Operated as a service for plugin users; documented in readme.txt under
	 * "External services". Override via the constant to opt out.
	 */
	const DEFAULT_PROXY_URL = 'https://radicalsocials.wpcomstaging.com';

	public static function init(): void {
		add_action( 'rest_api_init',          [ __CLASS__, 'register_routes' ] );
		add_action( 'admin_init',             [ __CLASS__, 'handle_connect_action' ] );
		// The OAuth flow needs to redirect the browser off-site to
		// WordPress.com (and to the configured broker, when one is
		// in use). wp_safe_redirect() refuses cross-host hops unless
		// the host appears in allowed_redirect_hosts — so we register
		// the destinations we use here.
		add_filter( 'allowed_redirect_hosts', [ __CLASS__, 'add_allowed_oauth_hosts' ] );
	}

	/**
	 * Whitelist the OAuth destinations so wp_safe_redirect() can hop
	 * to them instead of bouncing back to /wp-admin/.
	 *
	 * @param string[] $hosts
	 * @return string[]
	 */
	public static function add_allowed_oauth_hosts( $hosts ): array {
		$hosts   = is_array( $hosts ) ? $hosts : [];
		$hosts[] = 'public-api.wordpress.com';

		// If the OAuth broker is configured, add its host too. parse_url
		// returns null for malformed URLs, which array_filter strips.
		$broker_url = self::is_using_proxy() ? self::proxy_url() : '';
		$broker_host = $broker_url ? wp_parse_url( $broker_url, PHP_URL_HOST ) : null;
		if ( $broker_host ) {
			$hosts[] = $broker_host;
		}

		return array_values( array_unique( array_filter( $hosts ) ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::CALLBACK_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'handle_callback' ],
				'permission_callback' => '__return_true', // state nonce provides security
			]
		);
	}

	/**
	 * URL for the "Connect WP.com Account" button.
	 *
	 * Returns a nonce-protected admin link to our own admin_init handler
	 * (handle_connect_action), not the WP.com authorize URL directly. The
	 * handler does the broker round-trip (or builds the direct authorize URL)
	 * at click time and then redirects the browser to WP.com. This keeps
	 * the settings page render cheap — no broker call per page load.
	 */
	public static function connect_url(): string {
		return wp_nonce_url(
			admin_url( 'admin.php?page=radical-socials-settings&tab=following&rs_action=wpcom_connect' ),
			'rs_wpcom_connect'
		);
	}

	/**
	 * Handle a click on the Connect button. Lives on admin_init so we can
	 * wp_safe_redirect() out to WP.com before any HTML output.
	 */
	public static function handle_connect_action(): void {
		if ( ! isset( $_GET['rs_action'] ) || 'wpcom_connect' !== $_GET['rs_action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'rs_wpcom_connect' );

		$state = wp_generate_uuid4();

		if ( self::is_using_proxy() ) {
			$response = wp_safe_remote_post( self::proxy_url() . '/wp-json/radical-socials/v1/wpcom-proxy/init', [
				'timeout' => 10,
				'body'    => [
					'state'  => $state,
					'origin' => self::callback_url(),
				],
			] );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				Radical_Socials_Settings_Page::set_notice( [ 'oauth_error' => 'broker_unreachable' ] ); wp_safe_redirect( self::settings_url() );
				exit;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$authorize_url = isset( $body['authorize_url'] ) ? (string) $body['authorize_url'] : '';
			// Defensive: make sure the broker actually returned a WP.com URL.
			// (We control the broker, but this stops a misconfigured / hijacked
			// broker from being used as an open-redirect via our admin.)
			if ( ! self::is_wpcom_authorize_url( $authorize_url ) ) {
				Radical_Socials_Settings_Page::set_notice( [ 'oauth_error' => 'broker_bad_response' ] ); wp_safe_redirect( self::settings_url() );
				exit;
			}

			update_option( self::STATE_OPTION, $state, false );
			// add_allowed_oauth_hosts() above puts public-api.wordpress.com
			// (and the broker host, when used) on the safe-redirect list,
			// so this cross-host hop is allowed.
			wp_safe_redirect( $authorize_url );
			exit;
		}

		// Direct mode: build the authorize URL ourselves.
		update_option( self::STATE_OPTION, $state, false );
		$authorize_url = add_query_arg(
			[
				'client_id'     => RS_WPCOM_CLIENT_ID,
				'redirect_uri'  => self::callback_url(),
				'response_type' => 'code',
				'scope'         => 'global',
				'state'         => $state,
			],
			self::AUTHORIZE_URL
		);
		// Same justification as above — public-api.wordpress.com is on
		// the allowed_redirect_hosts list courtesy of our init filter.
		wp_safe_redirect( $authorize_url );
		exit;
	}

	/** True if the URL is on WordPress.com's OAuth authorize endpoint. */
	private static function is_wpcom_authorize_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		$parsed = wp_parse_url( $url );
		return ! empty( $parsed['scheme'] )
			&& 'https' === strtolower( $parsed['scheme'] )
			&& ! empty( $parsed['host'] )
			&& 'public-api.wordpress.com' === strtolower( $parsed['host'] )
			&& ! empty( $parsed['path'] )
			&& str_starts_with( $parsed['path'], '/oauth2/authorize' );
	}

	/**
	 * REST callback — exchanges the authorization code for an access token.
	 */
	public static function handle_callback( WP_REST_Request $request ): void {
		// Validate state to prevent CSRF.
		$state    = $request->get_param( 'state' );
		$expected = get_option( self::STATE_OPTION, '' );

		if ( ! $state || ! hash_equals( (string) $expected, (string) $state ) ) {
			Radical_Socials_Settings_Page::set_notice( [ 'oauth_error' => 'state_mismatch' ] ); wp_safe_redirect( self::settings_url() );
			exit;
		}
		delete_option( self::STATE_OPTION );

		$code = $request->get_param( 'code' );
		if ( ! $code ) {
			Radical_Socials_Settings_Page::set_notice( [ 'oauth_error' => 'no_code' ] ); wp_safe_redirect( self::settings_url() );
			exit;
		}

		// Exchange code for access token. In proxy mode the secret lives on
		// the broker, not here, so we ask the broker to do the swap on our
		// behalf. In direct mode we POST to WP.com ourselves.
		if ( self::is_using_proxy() ) {
			$response = wp_safe_remote_post(
				self::proxy_url() . '/wp-json/radical-socials/v1/wpcom-proxy/exchange',
				[
					'timeout' => 15,
					'body'    => [
						'state' => $state,
						'code'  => $code,
					],
				]
			);
		} else {
			$response = wp_safe_remote_post(
				self::TOKEN_URL,
				[
					'body' => [
						'client_id'     => RS_WPCOM_CLIENT_ID,
						'client_secret' => RS_WPCOM_CLIENT_SECRET,
						'code'          => $code,
						'redirect_uri'  => self::callback_url(),
						'grant_type'    => 'authorization_code',
					],
					'timeout' => 15,
				]
			);
		}

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			Radical_Socials_Settings_Page::set_notice( [ 'oauth_error' => 'token_exchange_failed' ] ); wp_safe_redirect( self::settings_url() );
			exit;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			Radical_Socials_Settings_Page::set_notice( [ 'oauth_error' => 'no_token' ] ); wp_safe_redirect( self::settings_url() );
			exit;
		}

		update_option( self::TOKEN_OPTION, sanitize_text_field( $body['access_token'] ), false );

		// Kick off an immediate fetch now that we have a token.
		wp_schedule_single_event( time(), Radical_Socials_Following::FETCH_HOOK );
		spawn_cron();

		Radical_Socials_Settings_Page::set_notice( [ 'oauth_connected' => true ] ); wp_safe_redirect( self::settings_url() );
		exit;
	}

	public static function disconnect(): void {
		delete_option( self::TOKEN_OPTION );
	}

	public static function get_token(): string {
		return (string) get_option( self::TOKEN_OPTION, '' );
	}

	public static function is_connected(): bool {
		return '' !== self::get_token();
	}

	/**
	 * Always true — every install can connect to WP.com, either through its
	 * own registered app (direct mode, when an admin defined the constants)
	 * or through the bundled broker. The "Connect WP.com" button no longer
	 * needs to be hidden on un-configured sites.
	 */
	public static function is_configured(): bool {
		return true;
	}

	/**
	 * True when this install delegates the OAuth handshake to a broker
	 * (the default unless the admin defined their own client credentials).
	 */
	public static function is_using_proxy(): bool {
		return ! self::has_direct_credentials();
	}

	/** True when this install has its own WP.com app credentials. */
	public static function has_direct_credentials(): bool {
		return defined( 'RS_WPCOM_CLIENT_ID' ) && RS_WPCOM_CLIENT_ID
			&& defined( 'RS_WPCOM_CLIENT_SECRET' ) && RS_WPCOM_CLIENT_SECRET;
	}

	/**
	 * Broker URL: a site-defined override (RS_WPCOM_PROXY_URL) wins, otherwise
	 * we use the project's hosted broker (DEFAULT_PROXY_URL).
	 */
	private static function proxy_url(): string {
		$override = defined( 'RS_WPCOM_PROXY_URL' ) && RS_WPCOM_PROXY_URL ? (string) RS_WPCOM_PROXY_URL : '';
		return untrailingslashit( $override ?: self::DEFAULT_PROXY_URL );
	}

	private static function callback_url(): string {
		return rest_url( self::REST_NAMESPACE . self::CALLBACK_ROUTE );
	}

	private static function settings_url( string $query = '' ): string {
		$url = admin_url( 'admin.php?page=radical-socials-settings' );
		return $query ? $url . '&' . $query : $url;
	}
}

Radical_Socials_WPCOM_OAuth::init();
