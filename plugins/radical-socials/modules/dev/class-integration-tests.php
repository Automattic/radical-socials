<?php
/**
 * WP-CLI integration coverage for server-side following flows.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Integration_Tests {

	/** @var array<string, array|WP_Error> */
	private array $http_mocks = [];

	/** @var int[] */
	private array $created_users = [];

	/** @var int[] */
	private array $created_posts = [];

	/**
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public static function run( array $args = [], array $assoc_args = [] ): void {
		unset( $args, $assoc_args );

		try {
			$tests = new self();
			$tests->execute();
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	private function execute(): void {
		$restore = $this->snapshot_options( [
			'rs_rss_subscriptions',
			'rs_websub_subscriptions',
			'rs_following_favorites',
			'rs_last_feed_fetch',
		] );
		$original_user_id = get_current_user_id();

		try {
			$this->test_opml_round_trip();
			$this->pass( 'OPML parse/import/export' );

			$this->test_rss_canonical_urls();
			$this->pass( 'RSS canonical URL handling' );

			$this->test_websub_verification();
			$this->pass( 'WebSub challenge verification' );

			$this->test_rest_permissions_and_deletes();
			$this->pass( 'REST permission/delete behavior' );
		} finally {
			$this->restore_options( $restore );
			wp_set_current_user( $original_user_id );

			foreach ( $this->created_users as $user_id ) {
				if ( ! function_exists( 'wp_delete_user' ) ) {
					require_once ABSPATH . 'wp-admin/includes/user.php';
				}
				wp_delete_user( $user_id );
			}

			foreach ( $this->created_posts as $post_id ) {
				wp_delete_post( $post_id, true );
			}
		}

		WP_CLI::success( 'Radical Socials integration checks passed.' );
	}

	private function test_opml_round_trip(): void {
		update_option( 'rs_rss_subscriptions', [], false );

		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<opml version="1.0"><body>'
			. '<outline text="News">'
			. '<outline type="rss" text="Example Feed" xmlUrl="https://93.184.216.34/rss" htmlUrl="https://example.com/" />'
			. '</outline>'
			. '</body></opml>';

		$feeds = Radical_Socials_OPML::parse( $xml );
		$this->assert_same( 1, count( $feeds ), 'OPML parser should find one feed.' );
		$this->assert_same( 'https://93.184.216.34/rss', $feeds[0]['url'], 'OPML parser should preserve feed URL.' );
		$this->assert_same( [ 'News' ], $feeds[0]['categories'], 'OPML parser should preserve folder categories.' );

		$result = Radical_Socials_OPML::import( $feeds );
		$this->assert_same( 1, $result['added'], 'OPML import should add the feed.' );
		$this->assert_same( 0, $result['updated'], 'OPML import should not update on first import.' );

		$export = Radical_Socials_OPML::export();
		$this->assert_contains( 'xmlUrl="https://93.184.216.34/rss"', $export, 'OPML export should include the feed URL.' );
		$this->assert_contains( 'htmlUrl="https://example.com/"', $export, 'OPML export should include the source URL.' );
		$this->assert_contains( 'category="News"', $export, 'OPML export should include categories.' );
	}

	private function test_rss_canonical_urls(): void {
		$this->assert_true(
			! Radical_Socials_RSS_Fetcher::is_safe_remote_url( 'http://127.0.0.1/feed' ),
			'RSS URL validation should reject loopback addresses.'
		);
		$this->assert_true(
			! Radical_Socials_RSS_Fetcher::is_safe_remote_url( 'http://169.254.169.254/latest/meta-data/' ),
			'RSS URL validation should reject link-local metadata addresses.'
		);
		$this->assert_true(
			Radical_Socials_RSS_Fetcher::is_safe_remote_url( 'https://example.invalid/feed' ),
			'RSS URL validation should not reject ordinary hostnames only because DNS preflight cannot resolve them.'
		);
		$this->assert_true(
			! Radical_Socials_RSS_Fetcher::has_valid_remote_url_format( 'file:///tmp/feed.xml' ),
			'RSS URL validation should reject non-HTTP URL formats.'
		);

		update_option( 'rs_rss_subscriptions', [
			[
				'url'        => 'https://93.184.216.34/feed',
				'title'      => 'Example',
				'source_url' => 'https://example.com/',
			],
		], false );

		$this->with_http_mocks(
			[
				'HEAD https://93.184.216.34/feed' => $this->http_response( '', 200 ),
				'GET https://93.184.216.34/'      => $this->http_response(
					'<html><head><link rel="alternate" type="application/rss+xml" href="/feed.xml" /></head></html>',
					200
				),
			],
			function (): void {
				$request = new WP_REST_Request( 'POST', '/radical-socials/v1/following' );
				$request->set_param( 'input', 'http://93.184.216.34/feed' );

				$response = Radical_Socials_Following_REST::add_following( $request );
				$this->assert_same( 409, $response->get_status(), 'RSS add should reject duplicates after URL resolution.' );

				$resolved = Radical_Socials_RSS_Fetcher::resolve_url( 'https://93.184.216.34/old-feed.xml' );
				$this->assert_same( 'https://93.184.216.34/feed.xml', $resolved, 'RSS discovery should resolve relative feed URLs against the homepage.' );
			}
		);
	}

	private function test_websub_verification(): void {
		$feed_url = 'https://example.com/feed';
		update_option( 'rs_websub_subscriptions', [
			$feed_url => [
				'hub'    => 'https://hub.example.com/',
				'secret' => 'secret',
			],
		], false );

		$request = new WP_REST_Request( 'GET', '/radical-socials/v1/websub/callback' );
		$request->set_query_params( [
			'hub.mode'      => 'subscribe',
			'hub.topic'     => $feed_url,
			'hub.challenge' => 'challenge-token',
		] );

		$response = Radical_Socials_WebSub_Subscriber::handle_verification( $request );
		$this->assert_same( 200, $response->get_status(), 'WebSub verification should accept a known dotted hub.topic.' );
		$this->assert_same( 'challenge-token', $response->get_data(), 'WebSub verification should echo hub.challenge.' );

		update_option( 'rs_last_feed_fetch', 0, false );

		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0"><channel>'
			. '<title>Example Feed</title>'
			. '<link>https://example.com/</link>'
			. '<item>'
			. '<title>Pushed item</title>'
			. '<link>https://example.com/pushed-item</link>'
			. '<description>Pushed content.</description>'
			. '<pubDate>Mon, 11 May 2026 12:00:00 +0000</pubDate>'
			. '</item>'
			. '</channel></rss>';

		$request = new WP_REST_Request( 'POST', '/radical-socials/v1/websub/callback' );
		$request->set_query_params( [ 'hub.topic' => $feed_url ] );
		$request->set_body( $body );
		$request->set_header( 'x_hub_signature', 'sha256=' . hash_hmac( 'sha256', $body, 'secret' ) );

		$response = Radical_Socials_WebSub_Subscriber::handle_notification( $request );
		$this->assert_same( 200, $response->get_status(), 'WebSub notification should accept a signed known topic.' );
		$this->assert_true( 0 < (int) get_option( 'rs_last_feed_fetch', 0 ), 'WebSub notification should update the last refresh timestamp.' );

		$posts = get_posts( [
			'post_type'   => 'rs_feed_item',
			'post_status' => 'publish',
			'name'        => md5( 'https://example.com/pushed-item' ),
			'fields'      => 'ids',
			'numberposts' => 1,
		] );
		$this->assert_true( ! empty( $posts ), 'WebSub notification should ingest pushed feed items.' );
		$this->created_posts[] = (int) $posts[0];
	}

	private function test_rest_permissions_and_deletes(): void {
		$this->ensure_rest_routes();

		$feed_url = 'https://delete.example.com/feed';
		update_option( 'rs_rss_subscriptions', [
			[
				'url'        => $feed_url,
				'title'      => 'Delete Me',
				'source_url' => 'https://delete.example.com/',
			],
		], false );

		wp_set_current_user( 0 );
		$response = $this->rest_request( 'DELETE', '/radical-socials/v1/following', [
			'type' => 'rss',
			'id'   => md5( $feed_url ),
			'url'  => $feed_url,
		] );
		$this->assert_true( in_array( $response->get_status(), [ 401, 403 ], true ), 'Unauthenticated delete should be rejected.' );

		wp_set_current_user( $this->get_admin_user_id() );
		$response = $this->rest_request( 'DELETE', '/radical-socials/v1/following', [
			'type' => 'rss',
			'id'   => md5( 'https://missing.example.com/feed' ),
			'url'  => 'https://missing.example.com/feed',
		] );
		$this->assert_same( 404, $response->get_status(), 'RSS delete should return 404 when no subscription matches.' );

		$response = $this->rest_request( 'DELETE', '/radical-socials/v1/following', [
			'type' => 'activitypub',
			'id'   => '123',
		] );
		$this->assert_same( 400, $response->get_status(), 'ActivityPub delete should require a URL.' );

		$response = $this->rest_request( 'DELETE', '/radical-socials/v1/following', [
			'type' => 'rss',
			'id'   => md5( $feed_url ),
			'url'  => $feed_url,
		] );
		$this->assert_same( 200, $response->get_status(), 'RSS delete should succeed for a stored subscription.' );
		$this->assert_same( [], (array) get_option( 'rs_rss_subscriptions', [] ), 'RSS delete should remove the stored subscription.' );
	}

	/**
	 * @param string[] $option_names
	 * @return array<string, mixed>
	 */
	private function snapshot_options( array $option_names ): array {
		$snapshot = [];
		foreach ( $option_names as $option_name ) {
			$value                    = get_option( $option_name, null );
			$snapshot[ $option_name ] = [
				'exists' => null !== $value,
				'value'  => $value,
			];
		}
		return $snapshot;
	}

	/**
	 * @param array<string, array{exists:bool,value:mixed}> $snapshot
	 */
	private function restore_options( array $snapshot ): void {
		foreach ( $snapshot as $option_name => $option ) {
			if ( $option['exists'] ) {
				update_option( $option_name, $option['value'], false );
			} else {
				delete_option( $option_name );
			}
		}
	}

	/**
	 * @param array<string, array|WP_Error> $mocks
	 */
	private function with_http_mocks( array $mocks, callable $callback ): void {
		$this->http_mocks = $mocks;
		add_filter( 'pre_http_request', [ $this, 'mock_http_request' ], 10, 3 );

		try {
			$callback();
		} finally {
			remove_filter( 'pre_http_request', [ $this, 'mock_http_request' ], 10 );
			$this->http_mocks = [];
		}
	}

	/**
	 * @param false|array|WP_Error $preempt
	 * @param array<string, mixed> $args
	 * @return array|WP_Error
	 */
	public function mock_http_request( $preempt, array $args, string $url ) {
		$method = strtoupper( $args['method'] ?? 'GET' );
		$key    = $method . ' ' . $url;

		if ( isset( $this->http_mocks[ $key ] ) ) {
			return $this->http_mocks[ $key ];
		}

		return new WP_Error( 'rs_integration_unmocked_request', 'Unexpected HTTP request during integration check: ' . $key );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function http_response( string $body, int $status ): array {
		return [
			'headers'  => [],
			'body'     => $body,
			'response' => [
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Error',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	private function ensure_rest_routes(): void {
		rest_get_server();
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function rest_request( string $method, string $route, array $params ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_ensure_response( rest_do_request( $request ) );
	}

	private function get_admin_user_id(): int {
		$admins = get_users( [
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		] );

		if ( ! empty( $admins ) ) {
			return (int) $admins[0];
		}

		$suffix  = wp_generate_uuid4();
		$user_id = wp_create_user( 'rs_integration_admin_' . $suffix, wp_generate_password(), 'rs_integration_admin_' . $suffix . '@example.com' );
		$this->assert_true( ! is_wp_error( $user_id ), 'Integration check should be able to create an administrator user.' );

		$user = new WP_User( $user_id );
		$user->set_role( 'administrator' );
		$this->created_users[] = (int) $user_id;

		return (int) $user_id;
	}

	private function pass( string $message ): void {
		WP_CLI::log( 'PASS: ' . $message );
	}

	private function assert_same( $expected, $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException(
				$message . ' Expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) . '.'
			);
		}
	}

	private function assert_contains( string $needle, string $haystack, string $message ): void {
		if ( ! str_contains( $haystack, $needle ) ) {
			throw new RuntimeException( $message . ' Missing: ' . $needle );
		}
	}

	private function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}
}

WP_CLI::add_command( 'radical-socials integration-tests', [ Radical_Socials_Integration_Tests::class, 'run' ] );
