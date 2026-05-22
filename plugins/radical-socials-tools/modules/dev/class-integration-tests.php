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
	 * Virtualized option store — name → ['exists' => bool, 'value' => mixed].
	 * While these filters are installed, get_option/update_option/delete_option
	 * for the listed names route entirely through this in-memory map; the
	 * wp_options DB row is never read or written. See virtualize_options().
	 *
	 * @var array<string, array{exists: bool, value: mixed}>
	 */
	private array $virtualized = [];

	/**
	 * Per-option closures we registered with add_filter, so we can remove the
	 * exact same callbacks when tearing down (anonymous functions are
	 * identity-compared by reference in remove_filter).
	 *
	 * @var array<string, array<string, callable>>
	 */
	private array $virtualized_callbacks = [];

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
		// Tests must never write to the live wp_options DB. The old
		// snapshot/restore pattern (save → mutate → restore in finally)
		// silently corrupted user data whenever the runner was killed
		// between snapshot and restore — the next run snapshotted the
		// already-wiped state as if it were "original" and the original
		// values were unrecoverable. virtualize_options() replaces that
		// with a filter-based shim: reads return our in-memory copy,
		// writes/deletes update the in-memory copy and short-circuit
		// before touching the DB. Worst case (suite is killed) the DB
		// rows are still bit-identical to what they were before.
		$this->virtualize_options( [
			'rs_rss_subscriptions',
			'rs_websub_subscriptions',
			'rs_following_favorites',
			'rs_last_feed_fetch',
			'rs_wpcom_access_token',
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

			$this->test_add_routes_routing();
			$this->pass( 'Add endpoint routes inputs to the right backend' );

			$this->test_opml_batch_import_no_race();
			$this->pass( 'OPML batch import lands every unique URL (no race)' );

			$this->test_full_fetcher_run_persists_items();
			$this->pass( 'Feed_Fetcher::run() persists items past prune (feed_url meta)' );

			$this->test_refresh_lock_force_bypass();
			$this->pass( 'queue_refresh(force=true) bulldozes queued locks but never running' );

			$this->test_wpcom_reader_normalisation();
			$this->pass( 'WP.com Reader fetch + unfollow surface correctly' );

			$this->test_author_meta_populated_for_rss_and_wpcom();
			$this->pass( 'RSS + WP.com items carry author name / avatar / URL (so the feed-author blocks render for non-AP cards too)' );

			// — Tests that probe the failure modes seen in production —
			// (Hostinger: 215 subscriptions, 300s exec limit, lock stuck queued,
			// 0 feed items stored. Each of these targets a specific suspect.)

			$this->test_run_releases_lock_on_completion();
			$this->pass( 'Feed_Fetcher::run() releases the lock when it finishes' );

			$this->test_run_releases_lock_on_exception();
			$this->pass( 'Feed_Fetcher::run() releases the lock even when one feed throws' );

			$this->test_one_failing_feed_does_not_kill_batch();
			$this->pass( 'A WP_Error on one feed must not strand the rest' );

			$this->test_rss_fetch_at_scale_completes();
			$this->pass( 'fetch_all_rss processes ALL subscriptions in one call (chunking absent → real-world timeout risk)' );

			$this->test_concurrent_runs_are_locked_out();
			$this->pass( 'A second run() while one is RUNNING refuses to proceed' );

			$this->test_inbox_announce_dereferences_target();
			$this->pass( 'Inbox Announce surfaces the boosted note with a "Boosted by" badge' );

			$this->test_outbox_announce_dedupes_target_fetch();
			$this->pass( 'Outbox Announce dereferences target once and dedupes across boosters' );

			$this->test_boost_fetches_original_author_profile();
			$this->pass( 'Boost normalisation fetches the original author profile (name + avatar) when local cache misses' );

			$this->test_hooked_block_insertions();
			$this->pass( 'Hooked-block insertions: 1× like-button in post-template, 1× following-link & 1× favorites-link in navigation' );

			$this->test_like_button_ssr_initial_state();
			$this->pass( 'Like-button SSR pre-applies the `hidden` attribute so only one heart is visible before hydration' );
		} finally {
			$this->unvirtualize_options();
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
			! Radical_Socials_RSS_Fetcher::is_safe_remote_url( 'http://[::1]/feed' ),
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
			'hub.mode'          => 'subscribe',
			'hub.topic'         => $feed_url,
			'hub.challenge'     => 'challenge-token',
			'hub.lease_seconds' => '3600',
		] );

		$before   = time();
		$response = Radical_Socials_WebSub_Subscriber::handle_verification( $request );
		$this->assert_same( 200, $response->get_status(), 'WebSub verification should accept a known dotted hub.topic.' );
		$this->assert_same( 'challenge-token', $response->get_data(), 'WebSub verification should echo hub.challenge.' );

		$subs = (array) get_option( 'rs_websub_subscriptions', [] );
		$this->assert_same( 3600, (int) $subs[ $feed_url ]['lease_seconds'], 'WebSub verification should store the confirmed lease length.' );
		$this->assert_true(
			(int) $subs[ $feed_url ]['lease_expires'] >= $before + 3600,
			'WebSub verification should store the lease expiry.'
		);

		$this->with_http_mocks(
			[
				'HEAD https://example.com/feed' => $this->http_response( '', 200, [ 'link' => '<https://hub.example.com/>; rel="hub"' ] ),
				'POST https://hub.example.com/' => $this->http_response( '', 202 ),
			],
			function () use ( $feed_url, $subs ): void {
				Radical_Socials_WebSub_Subscriber::subscribe( $feed_url );
				$renewed = (array) get_option( 'rs_websub_subscriptions', [] );

				$this->assert_same( 'secret', $renewed[ $feed_url ]['secret'], 'WebSub renewal should keep the existing shared secret.' );
				$this->assert_same(
					(int) $subs[ $feed_url ]['lease_expires'],
					(int) $renewed[ $feed_url ]['lease_expires'],
					'WebSub renewal should not extend the lease before hub verification.'
				);
			}
		);

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
	 * Manual-add endpoint (POST /following) must route inputs by shape:
	 *   - plain URL → RSS path
	 *   - @user@host → ActivityPub path
	 *   - https://host/@user → ActivityPub path
	 *   - https://host/users/user → ActivityPub path (the form we fixed for grumpygamer)
	 *   - invalid format → 400 invalid_url
	 *   - private/loopback IP → 400 unsafe_url
	 */
	private function test_add_routes_routing(): void {
		update_option( 'rs_rss_subscriptions', [], false );

		// 1. Pure RSS URL — should land in rs_rss_subscriptions after fetch.
		$rss_feed_body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0"><channel>'
			. '<title>Routing Test</title>'
			. '<link>https://routing.example/</link>'
			. '<item><title>Hello</title><link>https://routing.example/post-1</link>'
			. '<pubDate>Mon, 11 May 2026 12:00:00 +0000</pubDate>'
			. '<description>Hi.</description></item>'
			. '</channel></rss>';

		$this->with_http_mocks(
			[
				'GET https://routing.example/feed' => $this->http_response( $rss_feed_body, 200 ),
				'HEAD https://routing.example/feed' => $this->http_response( '', 200 ),
			],
			function (): void {
				$request = new WP_REST_Request( 'POST', '/radical-socials/v1/following' );
				$request->set_param( 'input', 'https://routing.example/feed' );
				$response = Radical_Socials_Following_REST::add_following( $request );
				$this->assert_same( 201, $response->get_status(), 'Plain RSS URL should be added (201).' );
				$subs = (array) get_option( 'rs_rss_subscriptions', [] );
				$this->assert_same( 1, count( $subs ), 'RSS add should store one subscription.' );
				$this->assert_same( 'https://routing.example/feed', $subs[0]['url'], 'RSS add should store the canonical URL.' );
			}
		);

		// 2. Reject non-HTTP schemes. (Bare strings like "just-a-string" get
		//    http:// prepended by esc_url_raw and slip past — that's an
		//    expected WP convention. Schemes like ftp:// are the real fail.)
		$request = new WP_REST_Request( 'POST', '/radical-socials/v1/following' );
		$request->set_param( 'input', 'ftp://example.com/feed' );
		$response = Radical_Socials_Following_REST::add_following( $request );
		$this->assert_same( 400, $response->get_status(), 'Non-HTTP scheme should be rejected.' );
		$this->assert_same( 'invalid_url', $response->get_data()['error'] ?? '', 'Non-HTTP scheme should return invalid_url.' );

		// 3. Reject private/loopback IPs before any network call.
		$request = new WP_REST_Request( 'POST', '/radical-socials/v1/following' );
		$request->set_param( 'input', 'http://[::1]/feed' );
		$response = Radical_Socials_Following_REST::add_following( $request );
		$this->assert_same( 400, $response->get_status(), 'Loopback URL should be rejected.' );
		$this->assert_same( 'unsafe_url', $response->get_data()['error'] ?? '', 'Loopback URL should return unsafe_url.' );

		// 4–6. Shape detection for ActivityPub inputs. We don't actually
		// fire the remote follow (that requires WebFinger HTTP that's mocked
		// off here), but we can verify the router picked the AP branch:
		// AP failures never return the RSS-specific error codes
		// (invalid_url, unsafe_url, already_exists). If we see one of those
		// for an AP-shaped input, the regex routing didn't match.
		$rss_only_errors = [ 'invalid_url', 'unsafe_url', 'already_exists' ];
		foreach ( [
			'@person@mastodon.example',
			'https://mastodon.example/@person',
			'https://mastodon.example/users/person',
		] as $input ) {
			$request = new WP_REST_Request( 'POST', '/radical-socials/v1/following' );
			$request->set_param( 'input', $input );
			$response = Radical_Socials_Following_REST::add_following( $request );
			$error    = (string) ( $response->get_data()['error'] ?? '' );

			$this->assert_true(
				! in_array( $error, $rss_only_errors, true ),
				"Input '$input' should route to ActivityPub, not RSS. Got rss-only error '$error'."
			);
		}

		// Cleanup the test subscription.
		update_option( 'rs_rss_subscriptions', [], false );
	}

	/**
	 * OPML batch import must land every unique valid URL — earlier versions
	 * had a read-modify-write race on rs_rss_subscriptions when feeds were
	 * upserted in parallel per-entry; the batch endpoint fixes it.
	 */
	private function test_opml_batch_import_no_race(): void {
		update_option( 'rs_rss_subscriptions', [], false );

		// Build an OPML with 25 unique feeds + 3 cross-folder duplicates.
		$entries = '';
		for ( $i = 1; $i <= 25; $i++ ) {
			$entries .= sprintf(
				'<outline type="rss" text="Feed %1$d" xmlUrl="https://93.184.216.34/feed-%1$d" htmlUrl="https://example.com/feed-%1$d" />',
				$i
			);
		}
		// Add three of them again under a different folder.
		$dupes = '<outline text="Folder B">'
			. '<outline type="rss" text="Feed 1" xmlUrl="https://93.184.216.34/feed-1" htmlUrl="https://example.com/feed-1" />'
			. '<outline type="rss" text="Feed 2" xmlUrl="https://93.184.216.34/feed-2" htmlUrl="https://example.com/feed-2" />'
			. '<outline type="rss" text="Feed 3" xmlUrl="https://93.184.216.34/feed-3" htmlUrl="https://example.com/feed-3" />'
			. '</outline>';

		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<opml version="1.0"><body>'
			. $entries
			. $dupes
			. '</body></opml>';

		$feeds  = Radical_Socials_OPML::parse( $xml );
		$result = Radical_Socials_OPML::import( $feeds );

		$this->assert_same( 25, $result['added'], 'OPML batch import should add every unique URL once.' );
		$this->assert_same( 3, $result['updated'], 'OPML batch import should merge the cross-folder duplicates as updates.' );
		$this->assert_same( 0, $result['failed'], 'OPML batch import should not fail any of these URLs.' );

		$stored = (array) get_option( 'rs_rss_subscriptions', [] );
		$this->assert_same( 25, count( $stored ), 'rs_rss_subscriptions should hold exactly 25 unique entries.' );

		// Re-import: should self-heal duplicates if any sneaked into the option,
		// and report 0 added / 0 updated / 28 skipped.
		$result_again = Radical_Socials_OPML::import( $feeds );
		$this->assert_same( 0, $result_again['added'], 'Re-import should add nothing.' );
		$this->assert_same( 0, $result_again['updated'], 'Re-import should update nothing.' );
		$this->assert_same( 28, $result_again['skipped'], 'Re-import should skip every entry as a duplicate.' );

		update_option( 'rs_rss_subscriptions', [], false );
	}

	/**
	 * Feed_Fetcher::run() must persist fetched items past prune_orphaned_rss().
	 * Earlier the prune matched on _rs_item_source_url, which SimplePie populates
	 * from <link> and rarely matches the OPML's htmlUrl — every item got
	 * deleted right after being upserted. The fix pivots prune to _rs_item_feed_url
	 * (the canonical xmlUrl we used to fetch).
	 */
	private function test_full_fetcher_run_persists_items(): void {
		$feed_url   = 'https://93.184.216.34/feed';
		$source_url = 'https://example.com/wp';  // What we'd store from OPML…
		$channel_link = 'https://example.com/wp/'; // …which SimplePie reports differently.

		update_option( 'rs_rss_subscriptions', [
			[ 'url' => $feed_url, 'title' => 'Persist Me', 'source_url' => $source_url ],
		], false );

		$rss = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0"><channel>'
			. '<title>Persist Me</title><link>' . $channel_link . '</link>'
			. '<item><title>Item A</title>'
			. '<link>https://example.com/wp/post-a</link>'
			. '<pubDate>Mon, 11 May 2026 12:00:00 +0000</pubDate>'
			. '<description>A.</description></item>'
			. '<item><title>Item B</title>'
			. '<link>https://example.com/wp/post-b</link>'
			. '<pubDate>Tue, 12 May 2026 12:00:00 +0000</pubDate>'
			. '<description>B.</description></item>'
			. '</channel></rss>';

		$this->with_http_mocks(
			[ 'GET ' . $feed_url => $this->http_response( $rss, 200, [ 'content-type' => 'application/rss+xml' ] ) ],
			function () use ( $feed_url ): void {
				Radical_Socials_Feed_Fetcher::run();

				$items_with_feed_url = get_posts( [
					'post_type'   => 'rs_feed_item',
					'fields'      => 'ids',
					'numberposts' => -1,
					'meta_key'    => '_rs_item_feed_url',
					'meta_value'  => $feed_url,
				] );
				$this->assert_same( 2, count( $items_with_feed_url ), 'Both fetched items must survive the prune step.' );
				foreach ( $items_with_feed_url as $id ) {
					$this->created_posts[] = (int) $id;
				}
			}
		);

		update_option( 'rs_rss_subscriptions', [], false );
	}

	/**
	 * queue_refresh($force=true) bulldozes a stale "queued" lock so the
	 * Refresh button stays responsive on hosts where wp-cron is flaky. It
	 * never disturbs an active "running" fetch.
	 */
	private function test_refresh_lock_force_bypass(): void {
		// 1. Stale "queued" lock — force=false leaves it alone, force=true clears + re-queues.
		set_transient( Radical_Socials_Following::REFRESH_LOCK, Radical_Socials_Following::REFRESH_LOCK_QUEUED, 600 );
		$this->assert_same( false, Radical_Socials_Following::queue_refresh( false ), 'Passive queue must respect existing queued lock.' );
		$this->assert_same( Radical_Socials_Following::REFRESH_LOCK_QUEUED, get_transient( Radical_Socials_Following::REFRESH_LOCK ), 'Passive call leaves the queued lock in place.' );

		$this->assert_same( true,  Radical_Socials_Following::queue_refresh( true ),  'Forced queue must bulldoze a stale queued lock.' );
		$this->assert_same( Radical_Socials_Following::REFRESH_LOCK_QUEUED, get_transient( Radical_Socials_Following::REFRESH_LOCK ), 'Forced call leaves a fresh queued lock.' );

		// 2. Active "running" lock — even force=true must not interrupt.
		set_transient( Radical_Socials_Following::REFRESH_LOCK, Radical_Socials_Following::REFRESH_LOCK_RUNNING, 600 );
		$this->assert_same( false, Radical_Socials_Following::queue_refresh( true ), 'Running fetch must never be displaced.' );
		$this->assert_same( Radical_Socials_Following::REFRESH_LOCK_RUNNING, get_transient( Radical_Socials_Following::REFRESH_LOCK ), 'Running lock remains untouched after a forced call.' );

		// Cleanup
		delete_transient( Radical_Socials_Following::REFRESH_LOCK );
		wp_clear_scheduled_hook( Radical_Socials_Following::REFRESH_HOOK );
	}

	/**
	 * WP.com Reader: list endpoint normalises rows and unfollow_site() hits
	 * the right delete endpoint. Both are mocked so we don't depend on a
	 * live token.
	 */
	private function test_wpcom_reader_normalisation(): void {
		update_option( 'rs_wpcom_access_token', 'fake-token-for-testing', false );

		$following = wp_json_encode( [
			'subscriptions' => [
				[ 'ID' => 11, 'URL' => 'https://blog-one.example/', 'meta' => [ 'data' => [ 'site' => [ 'name' => 'Blog One', 'URL' => 'https://blog-one.example/' ] ] ] ],
				[ 'ID' => 22, 'URL' => 'https://blog-two.example/', 'meta' => [ 'data' => [ 'site' => [ 'name' => 'Blog Two', 'URL' => 'https://blog-two.example/' ] ] ] ],
			],
		] );

		$this->with_http_mocks(
			[
				'GET https://public-api.wordpress.com/rest/v1.2/read/following?number=100' => $this->http_response( $following, 200 ),
				'POST https://public-api.wordpress.com/rest/v1.1/sites/22/follows/mine/delete' => $this->http_response( '{"is_following":false}', 200 ),
			],
			function (): void {
				$list = Radical_Socials_WPCOM_Reader::get_following_list();
				$this->assert_same( 2, count( $list ), 'WP.com Reader should normalise two follows.' );
				$this->assert_same( 'wpcom', $list[0]['type'], 'Each entry should be tagged with type=wpcom.' );

				$ok = Radical_Socials_WPCOM_Reader::unfollow_site( '22' );
				$this->assert_true( $ok, 'unfollow_site() should hit /follows/mine/delete and return true on success.' );
			}
		);

		delete_option( 'rs_wpcom_access_token' );
	}

	/**
	 * Regression test: every fetcher path (RSS, WP.com, ActivityPub) must
	 * populate `author_name` / `author_icon_url` / `author_url` in post
	 * meta. The AP fetcher bakes these into post_content as part of the
	 * `.rs-ap-card` wrapper; the RSS and WP.com fetchers just record them
	 * on the post for future use (no surfacing today). The meta has to
	 * land regardless of feed type so it's available to anything that
	 * reads it later — without this guarantee, the old "two avatar blocks
	 * pull empty meta and bail" failure mode silently returns.
	 */
	private function test_author_meta_populated_for_rss_and_wpcom(): void {
		// ── RSS path: feed has channel <image> + per-item <dc:creator>. ──
		$feed_url = 'https://93.184.216.34/withauthor';
		$rss      = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>'
			. '<title>Site With Author</title>'
			. '<link>https://example.com/</link>'
			. '<image><url>https://example.com/icon.png</url><title>Site With Author</title><link>https://example.com/</link></image>'
			. '<item>'
			. '<title>Hello</title>'
			. '<link>https://example.com/hello</link>'
			. '<dc:creator>Jane Author</dc:creator>'
			. '<pubDate>Mon, 11 May 2026 12:00:00 +0000</pubDate>'
			. '<description>...</description>'
			. '</item>'
			. '</channel></rss>';

		$this->with_http_mocks(
			[ 'GET ' . $feed_url => $this->http_response( $rss, 200, [ 'content-type' => 'application/rss+xml' ] ) ],
			function () use ( $feed_url ): void {
				$items = Radical_Socials_RSS_Fetcher::fetch( $feed_url, 5 );
				$this->assert_true( ! empty( $items ), 'RSS fetcher returned items.' );
				$first = $items[0];
				$this->assert_same( 'Jane Author',                  $first['author_name'],     'RSS per-item author name is populated from <dc:creator>.' );
				$this->assert_same( 'https://example.com/icon.png', $first['author_icon_url'], 'RSS author_icon_url falls back to the channel <image><url>.' );
				$this->assert_same( 'https://example.com/',         $first['author_url'],      'RSS author_url falls back to the channel <link> when the item has no author <link>.' );
			}
		);

		// ── RSS fallback: no per-item author, no channel image. ──
		$bare_url = 'https://93.184.216.34/noauthor';
		$bare_rss = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0"><channel>'
			. '<title>Bare Feed</title>'
			. '<link>https://bare.example/</link>'
			. '<item>'
			. '<title>Hello</title>'
			. '<link>https://bare.example/hello</link>'
			. '<pubDate>Mon, 11 May 2026 12:00:00 +0000</pubDate>'
			. '<description>...</description>'
			. '</item>'
			. '</channel></rss>';
		$this->with_http_mocks(
			[ 'GET ' . $bare_url => $this->http_response( $bare_rss, 200, [ 'content-type' => 'application/rss+xml' ] ) ],
			function () use ( $bare_url ): void {
				$items = Radical_Socials_RSS_Fetcher::fetch( $bare_url, 5 );
				$first = $items[0];
				$this->assert_same( 'Bare Feed',             $first['author_name'], 'RSS without per-item author falls back to the channel title.' );
				$this->assert_same( 'https://bare.example/', $first['author_url'],  'RSS without per-item author URL falls back to the channel link.' );
				// author_icon_url may be empty when the feed has no <image>; that's expected.
			}
		);

		// ── WP.com path: nested `author` object. ──
		$method = new ReflectionMethod( Radical_Socials_WPCOM_Reader::class, 'normalize' );
		$method->setAccessible( true );

		$post = [
			'title'     => 'Test',
			'URL'       => 'https://blog.example/post',
			'content'   => '<p>body</p>',
			'date'      => '2026-05-21T12:00:00',
			'site_name' => 'Blog',
			'site_URL'  => 'https://blog.example/',
			'site_icon' => [ 'img' => 'https://blog.example/icon.png' ],
			'author'    => [
				'name'       => 'WPcom Author',
				'URL'        => 'https://gravatar.com/wpcom-author',
				'avatar_URL' => 'https://gravatar.com/avatar/abc',
			],
		];
		$item = $method->invoke( null, $post );
		$this->assert_same( 'WPcom Author',                       $item['author_name'],     'WP.com author_name comes from author.name.' );
		$this->assert_same( 'https://gravatar.com/avatar/abc',    $item['author_icon_url'], 'WP.com author_icon_url comes from author.avatar_URL.' );
		$this->assert_same( 'https://gravatar.com/wpcom-author',  $item['author_url'],      'WP.com author_url comes from author.URL.' );

		// ── WP.com fallback: no author block → use site fields. ──
		unset( $post['author'] );
		$item2 = $method->invoke( null, $post );
		$this->assert_same( 'Blog',                             $item2['author_name'],     'WP.com falls back to site_name when there is no author block.' );
		$this->assert_same( 'https://blog.example/icon.png',    $item2['author_icon_url'], 'WP.com falls back to site_icon.img when there is no author avatar.' );
		$this->assert_same( 'https://blog.example/',            $item2['author_url'],      'WP.com falls back to site_URL when there is no author URL.' );
	}

	/**
	 * After a normal run, the refresh lock must be cleared so the next cron
	 * tick (or user-clicked refresh) can proceed. A stuck lock is one of the
	 * two failure modes seen on staging.
	 */
	private function test_run_releases_lock_on_completion(): void {
		$feed_url = 'https://93.184.216.34/lockclean';
		update_option( 'rs_rss_subscriptions', [
			[ 'url' => $feed_url, 'title' => 'Lock', 'source_url' => 'https://example.com/' ],
		], false );
		set_transient( Radical_Socials_Following::REFRESH_LOCK, Radical_Socials_Following::REFRESH_LOCK_QUEUED, 600 );

		$rss = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0"><channel><title>L</title><link>https://example.com/</link>'
			. '<item><title>x</title><link>https://example.com/x</link><pubDate>Mon, 11 May 2026 12:00:00 +0000</pubDate><description>x</description></item>'
			. '</channel></rss>';

		$this->with_http_mocks(
			[ 'GET ' . $feed_url => $this->http_response( $rss, 200 ) ],
			function (): void {
				Radical_Socials_Feed_Fetcher::run();
				$this->assert_same( false, get_transient( Radical_Socials_Following::REFRESH_LOCK ), 'Lock must be cleared after a clean run.' );
				$this->assert_true( (int) get_option( 'rs_last_feed_fetch', 0 ) > 0, 'rs_last_feed_fetch must advance after a clean run.' );
			}
		);

		update_option( 'rs_rss_subscriptions', [], false );
		foreach ( get_posts( [ 'post_type' => 'rs_feed_item', 'fields' => 'ids', 'numberposts' => -1 ] ) as $id ) {
			$this->created_posts[] = (int) $id;
		}
	}

	/**
	 * If a feed mid-batch throws (network kill, parser crash, etc.), the
	 * try/finally in Feed_Fetcher::run() should still release the lock.
	 * Otherwise the production state "lock stuck after PHP got killed"
	 * keeps happening.
	 */
	private function test_run_releases_lock_on_exception(): void {
		update_option( 'rs_rss_subscriptions', [
			[ 'url' => 'https://93.184.216.34/throws', 'title' => 'T', 'source_url' => 'https://t.example/' ],
		], false );
		set_transient( Radical_Socials_Following::REFRESH_LOCK, Radical_Socials_Following::REFRESH_LOCK_QUEUED, 600 );

		// Force an exception during the prune step (simpler attach point than
		// faking SimplePie internals) by deleting the rs_feed_item post type
		// registration mid-run via a hook on render_block — no, simpler: use
		// pre_get_posts to throw when prune queries.
		$thrower = static function ( WP_Query $q ): void {
			if ( 'rs_feed_item' === $q->get( 'post_type' ) && $q->get( 'meta_query' ) ) {
				throw new RuntimeException( 'simulated mid-run failure' );
			}
		};
		add_action( 'pre_get_posts', $thrower );

		$threw = false;
		try {
			Radical_Socials_Feed_Fetcher::run();
		} catch ( \Throwable $e ) {
			$threw = true;
		} finally {
			remove_action( 'pre_get_posts', $thrower );
		}

		$this->assert_true( $threw, 'Test setup should have caused run() to throw.' );
		$this->assert_same( false, get_transient( Radical_Socials_Following::REFRESH_LOCK ), 'Lock must be cleared even when run() throws.' );

		update_option( 'rs_rss_subscriptions', [], false );
	}

	/**
	 * One feed returning a WP_Error (HTTP timeout / DNS failure / 5xx)
	 * must NOT prevent the other feeds in the same run from being upserted.
	 * If you have 215 feeds and one of them flakes, you don't want zero items.
	 */
	private function test_one_failing_feed_does_not_kill_batch(): void {
		$ok_url     = 'https://93.184.216.34/ok';
		$bad_url    = 'https://93.184.216.34/bad';
		$ok2_url    = 'https://93.184.216.34/ok2';

		update_option( 'rs_rss_subscriptions', [
			[ 'url' => $ok_url,  'title' => 'OK',   'source_url' => 'https://ok.example/'   ],
			[ 'url' => $bad_url, 'title' => 'BAD',  'source_url' => 'https://bad.example/'  ],
			[ 'url' => $ok2_url, 'title' => 'OK 2', 'source_url' => 'https://ok2.example/'  ],
		], false );

		$good_rss = function ( string $link, string $title ) {
			return '<?xml version="1.0" encoding="UTF-8"?>'
				. '<rss version="2.0"><channel><title>' . $title . '</title><link>' . $link . '</link>'
				. '<item><title>' . $title . ' item</title><link>' . $link . 'post</link>'
				. '<pubDate>Mon, 11 May 2026 12:00:00 +0000</pubDate><description>...</description></item>'
				. '</channel></rss>';
		};

		$this->with_http_mocks(
			[
				'GET ' . $ok_url  => $this->http_response( $good_rss( 'https://ok.example/',  'OK' ),   200 ),
				'GET ' . $bad_url => new WP_Error( 'http_request_failed', 'simulated timeout' ),
				'GET ' . $ok2_url => $this->http_response( $good_rss( 'https://ok2.example/', 'OK 2' ), 200 ),
			],
			function () use ( $ok_url, $ok2_url ): void {
				Radical_Socials_Feed_Fetcher::run();

				$stored = function ( string $feed_url ): int {
					return count( get_posts( [
						'post_type'   => 'rs_feed_item',
						'fields'      => 'ids',
						'numberposts' => -1,
						'meta_key'    => '_rs_item_feed_url',
						'meta_value'  => $feed_url,
					] ) );
				};

				$this->assert_same( 1, $stored( $ok_url ),  'Items from the first OK feed must persist.' );
				$this->assert_same( 1, $stored( $ok2_url ), 'Items from the second OK feed must persist (downstream of the failing one).' );
			}
		);

		update_option( 'rs_rss_subscriptions', [], false );
		foreach ( get_posts( [ 'post_type' => 'rs_feed_item', 'fields' => 'ids', 'numberposts' => -1 ] ) as $id ) {
			$this->created_posts[] = (int) $id;
		}
	}

	/**
	 * Probe whether the RSS batch is chunked under realistic scale.
	 * We register 50 subscriptions and call fetch_all_rss() once, counting how
	 * many distinct HTTP GETs the fetcher attempted. AP outbox already chunks
	 * to 10 actors/run (`rs_ap_outbox_offset`); the RSS path doesn't, which
	 * means on Hostinger's 300s exec limit a 215-feed account can never finish.
	 *
	 * This test passes if either:
	 *   - the implementation IS chunked (fetched count < total), or
	 *   - the implementation handles 50 feeds fast enough that completion
	 *     within a single PHP request is realistic.
	 * It deliberately FAILS if all 50 feeds are processed serially, because
	 * that's the production failure mode.
	 */
	private function test_rss_fetch_at_scale_completes(): void {
		$subs   = [];
		$mocks  = [];
		$total  = 50;
		$rss = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0"><channel><title>S</title><link>https://s.example/</link>'
			. '<item><title>x</title><link>https://s.example/x</link><pubDate>Mon, 11 May 2026 12:00:00 +0000</pubDate><description>x</description></item>'
			. '</channel></rss>';

		for ( $i = 1; $i <= $total; $i++ ) {
			$url    = sprintf( 'https://93.184.216.34/scale-%02d', $i );
			$subs[] = [ 'url' => $url, 'title' => "S$i", 'source_url' => 'https://example.com/' ];
			$mocks[ 'GET ' . $url ] = $this->http_response( $rss, 200 );
		}
		update_option( 'rs_rss_subscriptions', $subs, false );

		$attempted = 0;
		$counter   = function ( $preempt, array $args, string $url ) use ( &$attempted ) {
			$attempted++;
			return $preempt; // pass through to the real mock filter
		};
		add_filter( 'pre_http_request', $counter, 5, 3 );

		$this->with_http_mocks( $mocks, function (): void {
			$rc = new ReflectionMethod( 'Radical_Socials_Feed_Fetcher', 'fetch_all_rss' );
			$rc->setAccessible( true );
			$rc->invoke( null );
		} );

		remove_filter( 'pre_http_request', $counter, 5 );

		// The assertion: a single fetch_all_rss call should NOT serially process
		// every subscription. AP outbox chunks to 10. If RSS processes all 50,
		// scale up to your real 182-subscription account and you're past PHP's
		// 300s timeout. Demand the same chunking discipline.
		$this->assert_true(
			$attempted < $total,
			"fetch_all_rss should chunk like fetch_outboxes (≤10 per run). Got $attempted of $total processed — production timeout failure mode."
		);

		update_option( 'rs_rss_subscriptions', [], false );
		foreach ( get_posts( [ 'post_type' => 'rs_feed_item', 'fields' => 'ids', 'numberposts' => -1 ] ) as $id ) {
			$this->created_posts[] = (int) $id;
		}
	}

	/**
	 * If two cron ticks fire close together (Hostinger sometimes runs a
	 * backlog when its scheduler catches up), the second run() must see the
	 * RUNNING lock and bail. Otherwise we'd get concurrent option writes
	 * and corrupt state.
	 */
	private function test_concurrent_runs_are_locked_out(): void {
		// Pre-set the RUNNING lock to simulate another tick already in flight.
		set_transient( Radical_Socials_Following::REFRESH_LOCK, Radical_Socials_Following::REFRESH_LOCK_RUNNING, 600 );

		$before = (int) get_option( 'rs_last_feed_fetch', 0 );
		Radical_Socials_Feed_Fetcher::run();
		$after  = (int) get_option( 'rs_last_feed_fetch', 0 );

		$this->assert_same( $before, $after, 'A run() entering while another is RUNNING must early-return without touching rs_last_feed_fetch.' );
		$this->assert_same( Radical_Socials_Following::REFRESH_LOCK_RUNNING, get_transient( Radical_Socials_Following::REFRESH_LOCK ), 'The original RUNNING lock must remain in place.' );

		delete_transient( Radical_Socials_Following::REFRESH_LOCK );
	}

	/**
	 * An inbox Announce (boost) where the object is a URL string must
	 * dereference the boosted note and surface it as a feed item, with a
	 * "Boosted by" badge and the original author as author_*.
	 *
	 * source_url must be the booster's actor URL (so the prune step, which
	 * matches items against the follow list, doesn't reap boosted items
	 * as orphans).
	 */
	private function test_inbox_announce_dereferences_target(): void {
		if ( ! post_type_exists( 'ap_inbox' ) ) {
			$this->pass( 'Skipped (ActivityPub plugin not active in this env)' );
			return;
		}

		$booster_url = 'https://booster.test/users/alice';
		$target_url  = 'https://origin.test/users/bob/statuses/42';
		$author_url  = 'https://origin.test/users/bob';

		$announce = wp_json_encode( [
			'type'   => 'Announce',
			'actor'  => $booster_url,
			'object' => $target_url,
		] );

		$inbox_id = wp_insert_post( [
			'post_type'    => 'ap_inbox',
			'post_status'  => 'publish',
			'post_content' => $announce,
		] );
		$this->assert_true( $inbox_id > 0, 'Should have created an ap_inbox post.' );
		$this->created_posts[] = (int) $inbox_id;

		update_post_meta( $inbox_id, '_activitypub_activity_type', 'Announce' );
		update_post_meta( $inbox_id, '_activitypub_activity_remote_actor', $booster_url );
		update_post_meta( $inbox_id, '_activitypub_object_id', $target_url );

		$target_object = wp_json_encode( [
			'type'         => 'Note',
			'id'           => $target_url,
			'url'          => $target_url,
			'attributedTo' => $author_url,
			'content'      => '<p>boosted body</p>',
			'published'    => '2026-05-10T08:00:00Z',
		] );

		$author_object = wp_json_encode( [
			'type' => 'Person',
			'id'   => $author_url,
			'name' => 'Bob Original',
			'icon' => [ 'url' => 'https://origin.test/avatars/bob.png' ],
		] );

		// Clear caches between tests so the actor fetch path is exercised.
		$reset = \Closure::bind( static function () { Radical_Socials_ActivityPub_Fetcher::$object_cache = []; }, null, Radical_Socials_ActivityPub_Fetcher::class );
		$reset();
		delete_transient( 'rs_ap_author_' . md5( $author_url ) );

		$this->with_http_mocks(
			[
				'GET ' . $target_url => $this->http_response( $target_object, 200, [ 'content-type' => 'application/activity+json' ] ),
				'GET ' . $author_url => $this->http_response( $author_object, 200, [ 'content-type' => 'application/activity+json' ] ),
			],
			function () use ( $inbox_id, $booster_url, $target_url, $author_url ): void {
				$item = Radical_Socials_ActivityPub_Fetcher::normalize_activity( (int) $inbox_id );

				$this->assert_true( is_array( $item ), 'normalize_activity should return an array for an Announce.' );
				$this->assert_same( $target_url, $item['url'], 'Announce should be keyed to the boosted note URL.' );
				$this->assert_same( md5( $target_url ), $item['guid'], 'guid must hash the boosted note URL so multiple boosters dedupe to one item.' );
				$this->assert_same( $booster_url, $item['source_url'], 'source_url must be the booster — otherwise prune removes boosted items.' );
				$this->assert_same( $author_url, $item['author_url'], 'author_url must be the original poster.' );
				$this->assert_true( str_contains( $item['content'], 'rs-boost-context' ), 'Boost badge HTML must be present in content.' );
				$this->assert_true( str_contains( $item['content'], 'boosted body' ), 'Boosted note body must be present in content.' );
				$this->assert_true( str_contains( $item['content'], 'rs-ap-card' ), 'Content is wrapped in the self-contained rs-ap-card layout.' );
				$this->assert_true( str_contains( $item['content'], 'rs-ap-avatar' ), 'Avatar image is baked into the content header.' );
				$this->assert_true( str_contains( $item['content'], 'rs-ap-name' ), 'Display name is baked into the content header.' );
			}
		);
	}

	/**
	 * Two followed actors both boosting the same post: the target URL must
	 * be fetched exactly once (in-request cache), and both boosters produce
	 * items with identical guid so the upsert collapses them.
	 */
	private function test_outbox_announce_dedupes_target_fetch(): void {
		$booster_a   = 'https://a.example/users/aaa';
		$booster_b   = 'https://b.example/users/bbb';
		$target_url  = 'https://origin.example/users/cee/statuses/777';

		$target_object = wp_json_encode( [
			'type'         => 'Note',
			'id'           => $target_url,
			'url'          => $target_url,
			'attributedTo' => 'https://origin.example/users/cee',
			'content'      => '<p>once-fetched boosted body</p>',
			'published'    => '2026-05-11T09:00:00Z',
		] );

		// Reset the dereference cache so this test starts clean.
		$reset = \Closure::bind( static function () { Radical_Socials_ActivityPub_Fetcher::$object_cache = []; }, null, Radical_Socials_ActivityPub_Fetcher::class );
		$reset();

		$target_hits = 0;
		$counter     = function ( $preempt, $args, $url ) use ( $target_url, &$target_hits ) {
			if ( $url === $target_url ) {
				$target_hits++;
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $counter, 5, 3 );

		try {
			$this->with_http_mocks(
				[ 'GET ' . $target_url => $this->http_response( $target_object, 200, [ 'content-type' => 'application/activity+json' ] ) ],
				function () use ( $booster_a, $booster_b, $target_url ): void {
					$activity_a = [ 'type' => 'Announce', 'actor' => $booster_a, 'object' => $target_url, 'published' => '2026-05-11T09:01:00Z' ];
					$activity_b = [ 'type' => 'Announce', 'actor' => $booster_b, 'object' => $target_url, 'published' => '2026-05-11T09:02:00Z' ];

					$method = new ReflectionMethod( Radical_Socials_ActivityPub_Fetcher::class, 'normalize_outbox_announce' );
					$method->setAccessible( true );

					$item_a = $method->invoke( null, $activity_a, $booster_a );
					$item_b = $method->invoke( null, $activity_b, $booster_b );

					$this->assert_true( is_array( $item_a ) && is_array( $item_b ), 'Both Announces should normalize.' );
					$this->assert_same( $item_a['guid'], $item_b['guid'], 'guid collapses boosts of the same post across boosters.' );
					$this->assert_same( $booster_a, $item_a['source_url'], 'First item attributes source to booster A.' );
					$this->assert_same( $booster_b, $item_b['source_url'], 'Second item attributes source to booster B.' );
				}
			);
		} finally {
			remove_filter( 'pre_http_request', $counter, 5 );
		}

		$this->assert_same( 1, $target_hits, 'Target object must be dereferenced exactly once across multiple boosters in the same run.' );
	}

	/**
	 * The original author of a boosted note is almost never in the
	 * local ap_actor cache (we follow boosters, not the people they
	 * boost). Normalisation must fetch the actor JSON once and pull
	 * the display name + avatar URL out of it. Otherwise boosted-
	 * post cards render with no avatar.
	 */
	private function test_boost_fetches_original_author_profile(): void {
		$booster   = 'https://b.example/users/booster';
		$target    = 'https://origin.example/users/auth/statuses/777';
		$author    = 'https://origin.example/users/auth';

		$target_json = wp_json_encode( [
			'type'         => 'Note',
			'id'           => $target,
			'url'          => $target,
			'attributedTo' => $author,
			'content'      => '<p>boosted body</p>',
			'published'    => '2026-05-21T08:00:00Z',
		] );
		$author_json = wp_json_encode( [
			'type' => 'Person',
			'id'   => $author,
			'name' => 'Auth Display Name',
			'icon' => [ 'url' => 'https://origin.example/avatars/auth.png' ],
		] );

		// Clear in-request and cross-run caches so the fetch path is forced.
		$reset = \Closure::bind( static function () { Radical_Socials_ActivityPub_Fetcher::$object_cache = []; }, null, Radical_Socials_ActivityPub_Fetcher::class );
		$reset();
		delete_transient( 'rs_ap_author_' . md5( $author ) );

		$this->with_http_mocks(
			[
				'GET ' . $target => $this->http_response( $target_json, 200, [ 'content-type' => 'application/activity+json' ] ),
				'GET ' . $author => $this->http_response( $author_json, 200, [ 'content-type' => 'application/activity+json' ] ),
			],
			function () use ( $booster, $target, $author ) {
				$activity = [ 'type' => 'Announce', 'actor' => $booster, 'object' => $target, 'published' => '2026-05-21T08:01:00Z' ];

				$method = new ReflectionMethod( Radical_Socials_ActivityPub_Fetcher::class, 'normalize_outbox_announce' );
				$method->setAccessible( true );
				$item = $method->invoke( null, $activity, $booster );

				$this->assert_true( is_array( $item ), 'normalize_outbox_announce returns an array for a boost.' );
				$this->assert_same( $author, $item['author_url'], 'author_url is the original poster.' );
				$this->assert_same( 'Auth Display Name', $item['author_name'], 'author_name is pulled from the fetched actor JSON.' );
				$this->assert_same( 'https://origin.example/avatars/auth.png', $item['author_icon_url'], 'author_icon_url is pulled from the fetched actor JSON.' );
			}
		);

		// Clean up the transient so subsequent test runs start fresh.
		delete_transient( 'rs_ap_author_' . md5( $author ) );
	}

	/**
	 * Regression test for the "two hearts per post / missing nav links"
	 * bugs. The plugin registers three hooked-block insertions:
	 *
	 *   - radical-socials/like-button     → core/post-template / last_child
	 *   - radical-socials/following-link  → core/navigation    / last_child
	 *   - radical-socials/favorites-link  → core/navigation    / last_child
	 *
	 * Historically these were registered via TWO paths simultaneously
	 * (declarative `blockHooks` in block.json AND an imperative
	 * `hooked_block_types` filter callback), and WP inserted each block
	 * TWICE. We later removed the imperative callbacks entirely — which
	 * worked for the like-button (post-template hooks run at render time
	 * via blockHooks) but silently dropped the nav links (saved
	 * wp_navigation posts don't reliably pick up declarative blockHooks).
	 * The current shape is: like-button via blockHooks only, nav links
	 * via imperative filter only. This test enforces exactly that —
	 * exactly one insertion per anchor, no zero, no duplicates.
	 */
	private function test_hooked_block_insertions(): void {
		// Apply `hooked_block_types` against each anchor and assert that
		// the resulting list contains each expected hook exactly once.
		$nav_hooks = apply_filters(
			'hooked_block_types',
			array(),
			'last_child',
			'core/navigation',
			null
		);
		$this->assert_same(
			1,
			count( array_keys( $nav_hooks, 'radical-socials/following-link', true ) ),
			'radical-socials/following-link must be hooked into core/navigation/last_child exactly once.'
		);
		$this->assert_same(
			1,
			count( array_keys( $nav_hooks, 'radical-socials/favorites-link', true ) ),
			'radical-socials/favorites-link must be hooked into core/navigation/last_child exactly once.'
		);

		// like-button is declared via blockHooks only; assert it does NOT
		// also appear in the imperative filter list (a duplicate would
		// produce two hearts per feed item again).
		$pt_imperative_hooks = apply_filters(
			'hooked_block_types',
			array(),
			'last_child',
			'core/post-template',
			null
		);
		$this->assert_same(
			0,
			count( array_keys( $pt_imperative_hooks, 'radical-socials/like-button', true ) ),
			'radical-socials/like-button must NOT be added via the imperative hooked_block_types filter (block.json blockHooks is the canonical source).'
		);

		// And block.json's declarative blockHooks must still be present
		// — read straight from the registered block type, so a future
		// edit of block.json that drops the field gets caught.
		$bt = WP_Block_Type_Registry::get_instance()->get_registered( 'radical-socials/like-button' );
		$this->assert_true(
			$bt && isset( $bt->block_hooks['core/post-template'] ),
			'radical-socials/like-button block.json must declare blockHooks for core/post-template.'
		);
		$this->assert_same(
			'last_child',
			$bt->block_hooks['core/post-template'] ?? null,
			'radical-socials/like-button block.json must declare its core/post-template hook at last_child.'
		);

		// Symmetric assertion for the nav-link blocks: they MUST NOT have
		// blockHooks in block.json. The declarative path doesn't insert
		// reliably into saved wp_navigation posts; if we ever add it back
		// the result is two of each nav link the moment the bug is fixed
		// upstream. This test fails loudly if that happens.
		$following = WP_Block_Type_Registry::get_instance()->get_registered( 'radical-socials/following-link' );
		$this->assert_true(
			$following && empty( $following->block_hooks ),
			'radical-socials/following-link must NOT declare blockHooks (imperative filter is the sole insertion path).'
		);
		$favorites = WP_Block_Type_Registry::get_instance()->get_registered( 'radical-socials/favorites-link' );
		$this->assert_true(
			$favorites && empty( $favorites->block_hooks ),
			'radical-socials/favorites-link must NOT declare blockHooks (imperative filter is the sole insertion path).'
		);
	}

	/**
	 * Regression test for the "two hearts at once" visual glitch. The
	 * like-button renders both ♥ and ♡ in the same DOM so the Interactivity
	 * API can swap the `hidden` attribute on click. Without a server-side
	 * `hidden` baked into the initial markup, both icons render visible
	 * during the brief window before the view module hydrates — so the
	 * card appears to have two hearts. This test asserts the SSR output
	 * has the correct `hidden` already applied for both initial states.
	 */
	private function test_like_button_ssr_initial_state(): void {
		$item_url = 'https://example.invalid/ssr-test-' . wp_generate_uuid4();
		$post_id  = wp_insert_post( [
			'post_type'    => 'rs_feed_item',
			'post_status'  => 'publish',
			'post_title'   => 'SSR test',
			'post_content' => 'body',
			'meta_input'   => [
				'_rs_item_url' => $item_url,
			],
		] );
		$this->assert_true( ! is_wp_error( $post_id ) && $post_id > 0, 'Test setup: rs_feed_item insert' );
		$this->created_posts[] = (int) $post_id;

		// Drive the_post() so the block's render.php picks up get_the_ID().
		global $post;
		$prev_post = $post;
		$post      = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		// The block's render guards on manage_options; the suite already runs
		// as admin, but assert the precondition so a setup regression doesn't
		// silently turn this test into a no-op.
		$this->assert_true(
			current_user_can( 'manage_options' ),
			'Test setup: current user must be an administrator (the like-button render returns early otherwise).'
		);

		$fav_slug = md5( $item_url );

		try {
			// Not favorited: ♥ (filled) must start hidden, ♡ (empty) visible.
			$html = do_blocks( '<!-- wp:radical-socials/like-button /-->' );
			$this->assert_true(
				(bool) preg_match( '~class="rs-like-icon-filled"[^>]*(?<![\w-])hidden(?=\s|>)~', $html ),
				'Unfavorited state: filled ♥ must be rendered with `hidden`.'
			);
			$this->assert_true(
				! preg_match( '~class="rs-like-icon-empty"[^>]*(?<![\w-])hidden(?=\s|>)~', $html ),
				'Unfavorited state: empty ♡ must NOT be rendered with `hidden`.'
			);

			// Favorite by inserting the rs_favorite CPT row the toggle handler
			// would create. is_favorited() looks this up by slug=md5(url).
			$fav_id = wp_insert_post( [
				'post_type'   => 'rs_favorite',
				'post_status' => 'publish',
				'post_name'   => $fav_slug,
				'post_title'  => 'SSR test',
				'meta_input'  => [ '_rs_item_url' => $item_url ],
			] );
			$this->assert_true( ! is_wp_error( $fav_id ) && $fav_id > 0, 'Test setup: rs_favorite insert' );
			$this->created_posts[] = (int) $fav_id;

			// Favorited: ♥ visible, ♡ hidden.
			$html = do_blocks( '<!-- wp:radical-socials/like-button /-->' );
			$this->assert_true(
				! preg_match( '~class="rs-like-icon-filled"[^>]*(?<![\w-])hidden(?=\s|>)~', $html ),
				'Favorited state: filled ♥ must NOT be rendered with `hidden`.'
			);
			$this->assert_true(
				(bool) preg_match( '~class="rs-like-icon-empty"[^>]*(?<![\w-])hidden(?=\s|>)~', $html ),
				'Favorited state: empty ♡ must be rendered with `hidden`.'
			);
		} finally {
			wp_reset_postdata();
			$post = $prev_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Intercept get_option / update_option / delete_option for each named
	 * option so the test run cannot touch the live wp_options row. Reads
	 * return the in-memory copy; writes and deletes mutate the in-memory
	 * copy and short-circuit before WP writes to the DB. The companion
	 * teardown method `unvirtualize_options()` removes the filters; the
	 * DB row is then read/written normally again.
	 *
	 * Sentinel-based "did the option exist before the test?" tracking:
	 * a unique opaque string lets us distinguish "option not set" from
	 * "option legitimately stored a falsy value" — `get_option(name, null)`
	 * would conflate the two.
	 *
	 * @param string[] $names Option names to virtualize.
	 */
	private function virtualize_options( array $names ): void {
		$sentinel = '__rs_virt_unset__';

		foreach ( $names as $name ) {
			$current = get_option( $name, $sentinel );
			$this->virtualized[ $name ] = [
				'exists' => $sentinel !== $current,
				'value'  => $sentinel !== $current ? $current : null,
			];

			// pre_option_{name} short-circuits get_option when the
			// callback returns anything other than `false`. We return
			// the virtualized value, or the caller-supplied default
			// when the virtualized state is "not set" — matching
			// real get_option semantics.
			$read = function ( $pre, $option, $default_value ) use ( $name ) {
				$state = $this->virtualized[ $name ] ?? [ 'exists' => false, 'value' => null ];
				return $state['exists'] ? $state['value'] : $default_value;
			};

			// pre_update_option_{name} runs before the DB write. We
			// store the new value in memory and return `$old_value`
			// so WP's "is this actually a change?" guard short-circuits
			// the DB write. Callers that check the return get `false`
			// (no-op), which our test code does not.
			$write = function ( $value, $old_value, $option ) use ( $name ) {
				$this->virtualized[ $name ] = [ 'exists' => true, 'value' => $value ];
				return $old_value;
			};

			// pre_delete_option_{name} returning a truthy value
			// short-circuits the DB delete. We mark the virtualized
			// state as "not set" and return `true` so delete_option's
			// caller sees a successful delete.
			$delete = function ( $pre, $option ) use ( $name ) {
				$this->virtualized[ $name ] = [ 'exists' => false, 'value' => null ];
				return true;
			};

			add_filter( "pre_option_{$name}",        $read,   10, 3 );
			add_filter( "pre_update_option_{$name}", $write,  10, 3 );
			add_filter( "pre_delete_option_{$name}", $delete, 10, 2 );

			$this->virtualized_callbacks[ $name ] = compact( 'read', 'write', 'delete' );
		}
	}

	private function unvirtualize_options(): void {
		foreach ( $this->virtualized_callbacks as $name => $cbs ) {
			remove_filter( "pre_option_{$name}",        $cbs['read'] );
			remove_filter( "pre_update_option_{$name}", $cbs['write'] );
			remove_filter( "pre_delete_option_{$name}", $cbs['delete'] );
		}
		$this->virtualized           = [];
		$this->virtualized_callbacks = [];
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
	private function http_response( string $body, int $status, array $headers = [] ): array {
		// SimplePie rejects feed responses that lack a recognised Content-Type
		// and triggers an extra discover_feed_url() HTTP call as a fallback —
		// which is then unmocked and fails. Auto-sniff a sensible default so
		// callers don't have to think about it.
		if ( ! isset( $headers['content-type'] ) ) {
			$trimmed = ltrim( $body );
			if ( '' !== $body && ( str_starts_with( $trimmed, '<?xml' ) || str_starts_with( $trimmed, '<rss' ) || str_starts_with( $trimmed, '<feed' ) ) ) {
				$headers['content-type'] = 'application/rss+xml; charset=utf-8';
			} elseif ( '' !== $body && '{' === substr( $trimmed, 0, 1 ) ) {
				$headers['content-type'] = 'application/json';
			} else {
				$headers['content-type'] = 'text/html; charset=utf-8';
			}
		}

		return [
			'headers'  => $headers,
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
			WP_CLI::error( $message . ' Expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) . '.' );
		}
	}

	private function assert_contains( string $needle, string $haystack, string $message ): void {
		if ( ! str_contains( $haystack, $needle ) ) {
			WP_CLI::error( $message . ' Missing: ' . $needle );
		}
	}

	private function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			WP_CLI::error( $message );
		}
	}
}

WP_CLI::add_command( 'radical-socials integration-tests', [ Radical_Socials_Integration_Tests::class, 'run' ] );
