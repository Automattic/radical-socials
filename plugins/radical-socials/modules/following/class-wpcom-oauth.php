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

	const TOKEN_OPTION  = 'rs_wpcom_access_token';
	const STATE_OPTION  = 'rs_wpcom_oauth_state';
	const AUTHORIZE_URL = 'https://public-api.wordpress.com/oauth2/authorize';
	const TOKEN_URL     = 'https://public-api.wordpress.com/oauth2/token';
	const REST_NAMESPACE = 'radical-socials/v1';
	const CALLBACK_ROUTE = '/oauth/callback';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
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
	 * Build the WP.com authorization URL for the settings page connect button.
	 *
	 * Two paths:
	 *   - Direct mode (this site has its own CLIENT_ID + CLIENT_SECRET): build
	 *     the URL inline against WP.com.
	 *   - Proxy mode (RS_WPCOM_PROXY_URL points at a Radical Socials install
	 *     running in RS_WPCOM_PROXY_MODE): ask the proxy to register our
	 *     callback URL against a state token and return us the authorize URL.
	 *     The proxy's redirect_uri (not ours) is what's registered with
	 *     WP.com, so we never need our own app credentials.
	 */
	public static function connect_url(): string {
		$state = wp_generate_uuid4();

		if ( self::is_using_proxy() ) {
			$response = wp_remote_post( self::proxy_url() . '/wp-json/radical-socials/v1/wpcom-proxy/init', [
				'timeout' => 10,
				'body'    => [
					'state'  => $state,
					'origin' => self::callback_url(),
				],
			] );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return '';
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $body['authorize_url'] ) ) {
				return '';
			}

			update_option( self::STATE_OPTION, $state, false );
			return (string) $body['authorize_url'];
		}

		if ( ! self::has_direct_credentials() ) {
			return '';
		}

		update_option( self::STATE_OPTION, $state, false );

		return add_query_arg(
			[
				'client_id'     => RS_WPCOM_CLIENT_ID,
				'redirect_uri'  => self::callback_url(),
				'response_type' => 'code',
				'scope'         => 'global',
				'state'         => $state,
			],
			self::AUTHORIZE_URL
		);
	}

	/**
	 * REST callback — exchanges the authorization code for an access token.
	 */
	public static function handle_callback( WP_REST_Request $request ): void {
		// Validate state to prevent CSRF.
		$state    = $request->get_param( 'state' );
		$expected = get_option( self::STATE_OPTION, '' );

		if ( ! $state || ! hash_equals( (string) $expected, (string) $state ) ) {
			wp_safe_redirect( self::settings_url( 'rs_oauth_error=state_mismatch' ) );
			exit;
		}
		delete_option( self::STATE_OPTION );

		$code = $request->get_param( 'code' );
		if ( ! $code ) {
			wp_safe_redirect( self::settings_url( 'rs_oauth_error=no_code' ) );
			exit;
		}

		// Exchange code for access token. In proxy mode the secret lives on
		// the broker, not here, so we ask the broker to do the swap on our
		// behalf. In direct mode we POST to WP.com ourselves.
		if ( self::is_using_proxy() ) {
			$response = wp_remote_post(
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
			$response = wp_remote_post(
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
			wp_safe_redirect( self::settings_url( 'rs_oauth_error=token_exchange_failed' ) );
			exit;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			wp_safe_redirect( self::settings_url( 'rs_oauth_error=no_token' ) );
			exit;
		}

		update_option( self::TOKEN_OPTION, sanitize_text_field( $body['access_token'] ), false );

		// Kick off an immediate fetch now that we have a token.
		wp_schedule_single_event( time(), Radical_Socials_Following::FETCH_HOOK );
		spawn_cron();

		wp_safe_redirect( self::settings_url( 'rs_oauth=connected' ) );
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

	public static function is_configured(): bool {
		return self::is_using_proxy() || self::has_direct_credentials();
	}

	/** True when this install delegates the OAuth handshake to a broker. */
	public static function is_using_proxy(): bool {
		return defined( 'RS_WPCOM_PROXY_URL' ) && RS_WPCOM_PROXY_URL;
	}

	/** True when this install has its own WP.com app credentials. */
	public static function has_direct_credentials(): bool {
		return defined( 'RS_WPCOM_CLIENT_ID' ) && RS_WPCOM_CLIENT_ID
			&& defined( 'RS_WPCOM_CLIENT_SECRET' ) && RS_WPCOM_CLIENT_SECRET;
	}

	private static function proxy_url(): string {
		return self::is_using_proxy() ? untrailingslashit( (string) RS_WPCOM_PROXY_URL ) : '';
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
