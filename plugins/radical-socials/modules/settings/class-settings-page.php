<?php
/**
 * Settings Page
 *
 * Adds a Settings submenu under the Radical Socials top-level menu.
 * Provides admin-side forms for social profile identity plus site title and
 * logo settings.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Settings_Page {

	const PROFILE_HANDLE_META = 'rs_profile_handle';
	const PROFILE_AVATAR_META = 'rs_profile_avatar_id';
	const PROFILE_BANNER_META = 'rs_profile_banner_id';

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'admin_init',            [ __CLASS__, 'handle_profile_save' ] );
		add_action( 'admin_init',            [ __CLASS__, 'handle_following_privacy_save' ] );
		add_action( 'admin_init',            [ __CLASS__, 'handle_wpcom_disconnect' ] );
		add_action( 'admin_post_rs_install_activitypub', [ __CLASS__, 'handle_install_activitypub' ] );
		add_action( 'admin_post_rs_save_handle',         [ __CLASS__, 'handle_save_handle' ] );

		// Diagnostics is a developer-only surface. Skip the form-action
		// listener entirely on non-dev installs so the POST handler doesn't
		// even exist there (the nonce + capability checks make it safe
		// either way, but the cleaner shape is "no code path at all").
		if ( self::is_dev_mode() ) {
			add_action( 'admin_init', [ __CLASS__, 'handle_diagnostics_action' ] );
		}
	}

	/**
	 * Are we running in a development install? Diagnostics, fetch-now
	 * buttons, and other internal-introspection tools render only when
	 * this is true. The canonical signal is WP_DEBUG (set in wp-config.php
	 * on dev sites); RS_DEV is an opt-in override for the rare case
	 * someone wants Diagnostics on a non-debug install without flipping
	 * WP_DEBUG globally.
	 */
	public static function is_dev_mode(): bool {
		return ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			|| ( defined( 'RS_DEV' ) && RS_DEV );
	}

	/**
	 * Handle Diagnostics-tab actions (POST nonce-protected). Runs on
	 * admin_init so wp_safe_redirect() can set Location before any output.
	 *
	 * Supported actions (one per request):
	 *   rs_diag=run_fetch    — runs Feed_Fetcher::run() inline (blocking,
	 *                          up to 10 min). Last-resort when cron is dead.
	 *   rs_diag=queue_fetch  — queues a refresh through the normal cron path
	 *                          and clears any stuck lock.
	 *   rs_diag=clear_lock   — manually clears rs_feed_refresh_lock.
	 *   rs_diag=test_feed    — fetches the first RSS subscription URL with a
	 *                          short timeout to verify outbound HTTP works.
	 */
	public static function handle_diagnostics_action(): void {
		if ( ! isset( $_POST['rs_diagnostics_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( wp_unslash( $_POST['rs_diagnostics_nonce'] ), 'rs_diagnostics_action' ) ) {
			return;
		}

		$action = isset( $_POST['rs_diag'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_diag'] ) ) : '';
		$flag   = 'ok';

		switch ( $action ) {
			case 'clear_lock':
				delete_transient( Radical_Socials_Following::REFRESH_LOCK );
				$flag = 'lock_cleared';
				break;

			case 'queue_fetch':
				$queued = Radical_Socials_Following::queue_refresh( true );
				$flag   = $queued ? 'queued' : 'queue_failed';
				break;

			case 'run_fetch':
				// Last-resort inline run for hosts where cron doesn't fire.
				// We deliberately do NOT call set_time_limit() or ini_set()
				// here — wp.org Plugin Check rejects those, and most shared
				// hosts ignore the bump anyway. If the host's limits are too
				// tight for a full refresh, the chunking in Feed_Fetcher::run
				// already bounds the work per tick (10 RSS + 10 AP outboxes
				// + WP.com + inbox ≈ 30s wall time); the rest catches up on
				// subsequent ticks.
				delete_transient( Radical_Socials_Following::REFRESH_LOCK );
				$started = microtime( true );
				try {
					Radical_Socials_Feed_Fetcher::run();
					$flag = 'fetch_ran';
					set_transient( 'rs_diag_fetch_elapsed', round( microtime( true ) - $started, 1 ), 60 );
				} catch ( \Throwable $e ) {
					$flag = 'fetch_error';
					set_transient( 'rs_diag_fetch_error', $e->getMessage(), 60 );
				}
				break;

			case 'test_feed':
				$subs = (array) get_option( 'rs_rss_subscriptions', [] );
				if ( empty( $subs ) ) {
					$flag = 'no_subs';
					break;
				}
				$url = $subs[0]['url'] ?? '';
				if ( ! $url ) {
					$flag = 'no_subs';
					break;
				}
				$r = wp_safe_remote_get( $url, [ 'timeout' => 10, 'redirection' => 3 ] );
				if ( is_wp_error( $r ) ) {
					set_transient( 'rs_diag_test_result', [
						'url'   => $url,
						'ok'    => false,
						'error' => $r->get_error_message(),
					], 60 );
				} else {
					set_transient( 'rs_diag_test_result', [
						'url'   => $url,
						'ok'    => true,
						'code'  => (int) wp_remote_retrieve_response_code( $r ),
						'bytes' => strlen( (string) wp_remote_retrieve_body( $r ) ),
					], 60 );
				}
				$flag = 'tested';
				break;

			case 'test_feeds':
				// Same reasoning as run_fetch: no set_time_limit(). The
				// 20-feeds-per-click chunking below keeps each request well
				// under any reasonable max_execution_time.
				$results = (array) get_transient( 'rs_diag_feed_test_results' );
				if ( ! isset( $results['rows'] ) || ! is_array( $results['rows'] ) ) {
					$results = [ 'rows' => [] ];
				}
				$tested_urls = array_column( $results['rows'], 'url' );

				// Build the full target list: RSS subscriptions + ActivityPub actors.
				$targets = [];
				foreach ( (array) get_option( 'rs_rss_subscriptions', [] ) as $sub ) {
					if ( ! empty( $sub['url'] ) ) {
						$targets[] = [ 'url' => (string) $sub['url'], 'type' => 'rss' ];
					}
				}
				foreach ( get_posts( [ 'post_type' => 'ap_actor', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'all' ] ) as $actor_post ) {
					if ( ! empty( $actor_post->guid ) ) {
						$targets[] = [ 'url' => (string) $actor_post->guid, 'type' => 'activitypub' ];
					}
				}

				// Take the next 20 untested targets.
				$untested = array_values( array_filter( $targets, static fn( $t ) => ! in_array( $t['url'], $tested_urls, true ) ) );
				$batch    = array_slice( $untested, 0, 20 );

				if ( empty( $batch ) ) {
					$flag = 'feed_test_empty';
					break;
				}

				foreach ( $batch as $target ) {
					$start = microtime( true );
					$args  = 'activitypub' === $target['type']
						? Radical_Socials_ActivityPub_Fetcher::http_args( 8 )
						: [
							'timeout'            => 8,
							'redirection'        => Radical_Socials_RSS_Fetcher::HTTP_REDIRECTION,
							'reject_unsafe_urls' => true,
						];
					$response = wp_safe_remote_get( $target['url'], $args );
					$elapsed  = (int) round( ( microtime( true ) - $start ) * 1000 );

					$row = [
						'url'        => $target['url'],
						'type'       => $target['type'],
						'elapsed_ms' => $elapsed,
					];
					if ( is_wp_error( $response ) ) {
						$row['status'] = 0;
						$row['error']  = $response->get_error_message();
						$row['bytes']  = 0;
					} else {
						$row['status'] = (int) wp_remote_retrieve_response_code( $response );
						$row['error']  = '';
						$row['bytes']  = strlen( (string) wp_remote_retrieve_body( $response ) );
					}
					$results['rows'][] = $row;
				}

				$results['total_targets'] = count( $targets );
				$results['last_run']      = time();
				set_transient( 'rs_diag_feed_test_results', $results, HOUR_IN_SECONDS );
				$flag = 'feed_test_batch';
				break;

			case 'reset_feed_test':
				delete_transient( 'rs_diag_feed_test_results' );
				$flag = 'feed_test_reset';
				break;

			default:
				return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=radical-socials-settings&tab=diagnostics&rs_diag_result=' . rawurlencode( $flag ) ) );
		exit;
	}

	/**
	 * Handle the Following-tab privacy toggle POST. Lives on admin_init so we
	 * can wp_safe_redirect() before WP outputs the admin header — putting this
	 * inside render() would trigger "headers already sent" and leave the user
	 * staring at an empty admin page.
	 */
	/**
	 * Profile-tab Save handler. Must run on admin_init (NOT inside render())
	 * because we wp_safe_redirect() back on success — render() is invoked
	 * after the admin header has already been streamed to the browser, so
	 * a redirect at that point silently fails and the user gets a blank
	 * page where their settings used to be. Same lesson as the Following
	 * privacy form below.
	 */
	public static function handle_profile_save(): void {
		if ( ! isset( $_POST['rs_settings_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( wp_unslash( $_POST['rs_settings_nonce'] ), 'rs_settings_save' ) ) {
			return;
		}

		$user        = wp_get_current_user();
		$user_update = [ 'ID' => $user->ID ];

		if ( isset( $_POST['blogname'] ) ) {
			update_option( 'blogname', sanitize_text_field( wp_unslash( $_POST['blogname'] ) ) );
		}

		if ( isset( $_POST['rs_display_name'] ) ) {
			$user_update['display_name'] = sanitize_text_field( wp_unslash( $_POST['rs_display_name'] ) );
		}

		if ( isset( $_POST['rs_profile_website'] ) ) {
			$website = trim( wp_unslash( $_POST['rs_profile_website'] ) );

			if ( '' !== $website && ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $website ) ) {
				$website = 'https://' . $website;
			}

			$user_update['user_url'] = esc_url_raw( $website );
		}

		if ( count( $user_update ) > 1 ) {
			wp_update_user( $user_update );
		}

		if ( isset( $_POST['rs_profile_handle'] ) ) {
			update_user_meta(
				$user->ID,
				self::PROFILE_HANDLE_META,
				self::sanitize_profile_handle( wp_unslash( $_POST['rs_profile_handle'] ) )
			);
		}

		if ( isset( $_POST['rs_profile_bio'] ) ) {
			update_user_meta( $user->ID, 'description', sanitize_textarea_field( wp_unslash( $_POST['rs_profile_bio'] ) ) );
		}

		if ( current_user_can( 'upload_files' ) ) {
			self::save_image_meta( $user->ID, self::PROFILE_AVATAR_META, 'rs_profile_avatar_id' );
			self::save_image_meta( $user->ID, self::PROFILE_BANNER_META, 'rs_profile_banner_id' );
		}

		// custom_logo is always present in the POST (hidden input).
		// site-icon.js sets the value to 'false' (string) on remove.
		if ( array_key_exists( 'custom_logo', $_POST ) ) {
			$logo_id = absint( wp_unslash( $_POST['custom_logo'] ) );
			if ( $logo_id ) {
				set_theme_mod( 'custom_logo', $logo_id );
			} else {
				remove_theme_mod( 'custom_logo' );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=radical-socials-settings&tab=profile&rs_settings=updated' ) );
		exit;
	}

	/**
	 * WP.com disconnect (GET, nonce-protected). Also lives on admin_init
	 * so its wp_safe_redirect() lands before the admin header is sent.
	 */
	public static function handle_wpcom_disconnect(): void {
		if ( ! isset( $_GET['rs_action'] ) || 'wpcom_disconnect' !== $_GET['rs_action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'rs_wpcom_disconnect' ) ) {
			return;
		}

		Radical_Socials_WPCOM_OAuth::disconnect();
		wp_safe_redirect( admin_url( 'admin.php?page=radical-socials-settings&tab=following&rs_wpcom=disconnected' ) );
		exit;
	}

	public static function handle_following_privacy_save(): void {
		if ( ! isset( $_POST['rs_following_privacy_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( wp_unslash( $_POST['rs_following_privacy_nonce'] ), 'rs_following_privacy_save' ) ) {
			return;
		}
		update_option(
			Radical_Socials_Following::PUBLIC_OPTION,
			! empty( $_POST['rs_following_public'] ),
			false
		);
		update_option(
			'rs_purge_on_uninstall',
			! empty( $_POST['rs_purge_on_uninstall'] ),
			false
		);
		wp_safe_redirect( admin_url( 'admin.php?page=radical-socials-settings&tab=following&rs_settings=updated' ) );
		exit;
	}

	private static function active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab    = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'profile';
		$allowed = [ 'welcome', 'profile', 'following' ];
		if ( self::is_dev_mode() ) {
			$allowed[] = 'diagnostics';
		}
		return in_array( $tab, $allowed, true ) ? $tab : 'profile';
	}

	public static function enqueue( string $hook ): void {
		if ( 'toplevel_page_radical-socials-settings' !== $hook ) {
			return;
		}

		// Tab styling + welcome wizard cards live in settings.css — load it
		// on every tab so the header/tab bar is consistent everywhere.
		wp_enqueue_style(
			'rs-settings-page',
			plugin_dir_url( __FILE__ ) . 'assets/settings.css',
			[],
			filemtime( __DIR__ . '/assets/settings.css' ) ?: '1'
		);

		if ( 'profile' === self::active_tab() ) {
			wp_enqueue_media();
			wp_enqueue_script( 'site-icon' );
			wp_enqueue_style( 'site-icon' );

			wp_enqueue_script(
				'rs-settings-page',
				plugin_dir_url( __FILE__ ) . 'assets/settings.js',
				[ 'media-editor' ],
				filemtime( __DIR__ . '/assets/settings.js' ) ?: '1',
				true
			);
			wp_set_script_translations(
				'rs-settings-page',
				'radical-socials',
				plugin_dir_path( __FILE__ ) . '../../languages'
			);
		}

		// The Welcome wizard re-uses the Following tab's add-feed widgets
		// in Step 2, so enqueue the same JS + localized config there too.
		if ( in_array( self::active_tab(), [ 'following', 'welcome' ], true ) ) {
			wp_enqueue_script(
				'rs-following-settings',
				plugin_dir_url( __FILE__ ) . 'assets/following.js',
				[],
				filemtime( __DIR__ . '/assets/following.js' ) ?: '1',
				true
			);
			wp_set_script_translations(
				'rs-following-settings',
				'radical-socials',
				plugin_dir_path( __FILE__ ) . '../../languages'
			);
			$nonce = wp_create_nonce( 'wp_rest' );
			wp_localize_script( 'rs-following-settings', 'rsFollowing', [
				'activitypubAvailable' => class_exists( '\\Activitypub\\Collection\\Following' ),
				'apiUrl'              => rest_url( 'radical-socials/v1/following' ),
				'importFromAccountUrl' => rest_url( 'radical-socials/v1/following/import-from-account' ),
				'opmlParseUrl'        => rest_url( 'radical-socials/v1/following/opml/parse' ),
				'opmlEntryUrl'  => rest_url( 'radical-socials/v1/following/opml/entry' ),
				'opmlImportUrl' => rest_url( 'radical-socials/v1/following/opml/import' ),
				'opmlExportUrl' => add_query_arg( '_wpnonce', $nonce, rest_url( 'radical-socials/v1/following/opml/export' ) ),
				'nonce'         => $nonce,
				'i18n'          => [
					'loading'       => __( 'Loading…', 'radical-socials' ),
					'loadError'     => __( 'Could not load following list.', 'radical-socials' ),
					'empty'         => __( 'Not following anything yet. Add feeds or accounts above.', 'radical-socials' ),
					'remove'        => __( 'Remove', 'radical-socials' ),
					'deleteConfirm' => __( "Remove \"%name%\" (%url%)?\n\nThis will also delete all saved posts from this feed.", 'radical-socials' ),
					'deleteError'   => __( 'Could not remove item. Please try again.', 'radical-socials' ),
					'colFav'        => __( 'Fav', 'radical-socials' ),
					'colName'       => __( 'Name', 'radical-socials' ),
					'colHealth'     => __( 'Status', 'radical-socials' ),
					'colType'       => __( 'Type', 'radical-socials' ),
					'colCategories' => __( 'Categories', 'radical-socials' ),
					'healthOk'      => __( 'Healthy — replied in %ms% ms (checked %ago% ago)', 'radical-socials' ),
					'healthSlow'    => __( 'Slow — replied in %ms% ms (checked %ago% ago). If this stays in the orange, consider removing it.', 'radical-socials' ),
					'healthFailed'  => __( 'Failed: %error% (last attempt %ago% ago)', 'radical-socials' ),
					'healthUntested' => __( 'Not yet checked. The status updates after the next refresh cycle reaches this feed.', 'radical-socials' ),
					'healthUnknownError' => __( 'Unknown error', 'radical-socials' ),
					'starLabel'     => __( 'Star this feed', 'radical-socials' ),
					'unstarLabel'   => __( 'Unstar this feed', 'radical-socials' ),
					'addSummary'    => __( 'Done — %added% added, %skipped% already existed, %failed% failed.', 'radical-socials' ),
					'failuresLabel' => __( 'The following could not be added:', 'radical-socials' ),
					'errorNetwork'  => __( 'Network error', 'radical-socials' ),
					'errorUnknown'  => __( 'Unknown error', 'radical-socials' ),
					'errors'        => [
						'invalid_url'                       => __( 'Invalid URL — make sure it starts with https://', 'radical-socials' ),
						'unsafe_url'                        => __( 'URL must be a public HTTP(S) address', 'radical-socials' ),
						'activitypub_unavailable'           => __( 'ActivityPub plugin is not active — install it to follow Fediverse accounts', 'radical-socials' ),
						// AP plugin returns `activitypub_user_not_found` for the
						// LOCAL actor — i.e. this site doesn't have an ActivityPub
						// identity set up that can act. Different from the remote
						// "we couldn't find that account" case below.
						'activitypub_user_not_found'        => __( 'This site is not set up to follow on the Fediverse yet — check the ActivityPub plugin\'s settings', 'radical-socials' ),
						'activitypub_remote_actor_not_found' => __( 'Account not found — check the handle or URL', 'radical-socials' ),
						'activitypub_already_following'     => __( 'Already following', 'radical-socials' ),
						'already_exists'                    => __( 'Already following', 'radical-socials' ),
					],
					'importAccountBtn'     => __( 'Import follows', 'radical-socials' ),
					'importAccountFetching' => __( 'Fetching following list…', 'radical-socials' ),
					'importAccountAdding'   => __( 'Adding %done% / %total%…', 'radical-socials' ),
					'importAccountDone'     => __( 'Done — %added% added, %skipped% already existed, %failed% failed.', 'radical-socials' ),
					'importAccountPrivate'  => __( 'This account\'s following list is private. Enable "Show following and followers publicly" in your Mastodon privacy settings and try again.', 'radical-socials' ),
					'importAccountNotFound' => __( 'Account not found. Check the handle and try again.', 'radical-socials' ),
					'importAccountError'    => __( 'Could not fetch following list. Try again.', 'radical-socials' ),
					'importBtn'     => __( 'Import OPML', 'radical-socials' ),
					'importing'     => __( 'Importing…', 'radical-socials' ),
					'importResult'  => __( 'Done — %added% added, %updated% updated, %skipped% unchanged, %failed% failed.', 'radical-socials' ),
					'importError'   => __( 'Import failed. Make sure the file is a valid OPML document.', 'radical-socials' ),
					'importNoFile'  => __( 'Please choose an OPML file first.', 'radical-socials' ),
					'feedsHeading'  => __( 'Feeds (%count%)', 'radical-socials' ),
				],
			] );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = self::active_tab();
		$user       = wp_get_current_user();

		$logo_id     = (int) get_theme_mod( 'custom_logo' );
		$logo_url    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$has_logo    = (bool) $logo_url;
		$classes_for_preview = 'site-icon-preview wp-clearfix settings-page-preview' . ( $has_logo ? ' has-site-icon' : ' hidden' );
		$classes_for_button  = $has_logo ? 'button' : 'upload-button button-add-media button-add-site-icon';
		$classes_for_alt     = $has_logo ? 'upload-button button-add-media button-add-site-icon' : 'button';
		$display     = $user->display_name ?: $user->user_login;
		$handle      = self::get_profile_handle( $user );
		$bio         = get_user_meta( $user->ID, 'description', true );
		$bio_empty   = __( 'Add a short bio so people know what to expect from your profile.', 'radical-socials' );
		$bio_preview = $bio ?: $bio_empty;
		$website     = $user->user_url;
		$avatar_id   = (int) get_user_meta( $user->ID, self::PROFILE_AVATAR_META, true );
		$banner_id   = (int) get_user_meta( $user->ID, self::PROFILE_BANNER_META, true );
		$avatar_url  = $avatar_id ? wp_get_attachment_image_url( $avatar_id, 'thumbnail' ) : '';
		$banner_url  = $banner_id ? wp_get_attachment_image_url( $banner_id, 'large' ) : '';
		$initial     = strtoupper( substr( trim( $display ), 0, 1 ) );
		$initial     = $initial ?: 'R';

		// ── Tab URLs ─────────────────────────────────────────────────────────

		$tab_url = fn( string $tab ) => admin_url( 'admin.php?page=radical-socials-settings&tab=' . $tab );
		?>
		<div class="wrap rs-settings-wrap">
			<div class="rs-settings-header">
				<div class="rs-settings-title-section">
					<h1><?php esc_html_e( 'Radical Socials', 'radical-socials' ); ?></h1>
				</div>
				<div class="rs-settings-tabs-scroller">
					<nav class="rs-settings-tabs-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'radical-socials' ); ?>">
						<?php
						$tabs = [
							'welcome'   => __( 'Welcome', 'radical-socials' ),
							'profile'   => __( 'Profile', 'radical-socials' ),
							'following' => __( 'Following', 'radical-socials' ),
						];
						// Developer-only tab — hidden on non-debug installs.
						if ( self::is_dev_mode() ) {
							$tabs['diagnostics'] = __( 'Diagnostics', 'radical-socials' );
						}
						foreach ( $tabs as $slug => $label ) :
							$class = 'rs-settings-tab' . ( $slug === $active_tab ? ' active' : '' );
							?>
							<a href="<?php echo esc_url( $tab_url( $slug ) ); ?>" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</nav>
				</div>
			</div>

			<hr class="wp-header-end">

			<?php if ( isset( $_GET['rs_settings'] ) && 'updated' === $_GET['rs_settings'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'radical-socials' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rs_oauth'] ) && 'connected' === $_GET['rs_oauth'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'WP.com account connected. Your following feed will populate shortly.', 'radical-socials' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rs_wpcom'] ) && 'disconnected' === $_GET['rs_wpcom'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'WP.com account disconnected.', 'radical-socials' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rs_oauth_error'] ) ) :
				$oauth_error_msgs = [
					'broker_unreachable'    => __( 'The WP.com OAuth broker is unreachable. Try again in a minute, or set RS_WPCOM_CLIENT_ID / RS_WPCOM_CLIENT_SECRET in wp-config.php to use your own WordPress.com app instead.', 'radical-socials' ),
					'broker_bad_response'   => __( 'The WP.com OAuth broker returned an unexpected response. Try again.', 'radical-socials' ),
					'state_mismatch'        => __( 'Could not connect WP.com account — the connect attempt expired or didn\'t match this site. Click Connect again to start fresh.', 'radical-socials' ),
					'no_code'               => __( 'WordPress.com didn\'t return an authorization code. Click Connect to try again.', 'radical-socials' ),
					'token_exchange_failed' => __( 'Could not exchange the WordPress.com code for an access token. Try again.', 'radical-socials' ),
					'no_token'              => __( 'WordPress.com returned an unexpected response (no access token). Try again.', 'radical-socials' ),
				];
				$oauth_error_key = sanitize_text_field( wp_unslash( $_GET['rs_oauth_error'] ) );
				$oauth_error_msg = $oauth_error_msgs[ $oauth_error_key ] ?? __( 'Could not connect WP.com account. Please try again.', 'radical-socials' );
				?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $oauth_error_msg ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			$wide_tab        = in_array( $active_tab, [ 'following', 'diagnostics' ], true );
			$settings_class  = 'rs-settings' . ( $wide_tab ? ' rs-settings--wide' : '' );
			?>
			<div class="<?php echo esc_attr( $settings_class ); ?>">
			<?php if ( 'welcome' === $active_tab ) : ?>
				<?php self::render_welcome_tab(); ?>
			<?php elseif ( 'profile' === $active_tab ) : ?>

			<form method="post" class="rs-settings-form">
				<?php wp_nonce_field( 'rs_settings_save', 'rs_settings_nonce' ); ?>
				<div class="rs-settings-layout">
					<section class="rs-profile-preview" aria-label="<?php esc_attr_e( 'Profile preview', 'radical-socials' ); ?>">
						<div
							class="rs-profile-cover<?php echo $banner_url ? ' has-image' : ''; ?>"
							data-rs-profile-cover
							data-empty-label="<?php esc_attr_e( 'Cover photo', 'radical-socials' ); ?>"
							style="<?php echo $banner_url ? esc_attr( 'background-image: url("' . esc_url_raw( $banner_url ) . '");' ) : ''; ?>"
						>
							<?php if ( ! $banner_url ) : ?>
								<span><?php esc_html_e( 'Cover photo', 'radical-socials' ); ?></span>
							<?php endif; ?>
						</div>
						<div class="rs-profile-preview-body">
							<div class="rs-profile-avatar-preview" data-rs-profile-avatar data-empty-initial="<?php echo esc_attr( $initial ); ?>">
								<?php if ( $avatar_url ) : ?>
									<img src="<?php echo esc_url( $avatar_url ); ?>" alt="" />
								<?php else : ?>
									<span><?php echo esc_html( $initial ); ?></span>
								<?php endif; ?>
							</div>
							<div class="rs-profile-preview-copy">
								<h2>
									<?php echo esc_html( $display ); ?>
								</h2>
								<p class="rs-profile-handle">
									@<?php echo esc_html( $handle ); ?>
								</p>
								<p class="rs-profile-bio"><?php echo esc_html( $bio_preview ); ?></p>
								<?php
								$website_label = preg_replace( '#^https?://#i', '', $website );
								?>
								<p class="rs-profile-website<?php echo $website ? '' : ' is-empty'; ?>">
									<?php echo esc_html( untrailingslashit( $website_label ) ); ?>
								</p>
							</div>
						</div>
					</section>

					<div class="rs-settings-panels">
						<section class="rs-settings-panel">
							<h2><?php esc_html_e( 'Profile', 'radical-socials' ); ?></h2>
							<div class="rs-field">
								<label for="rs_display_name"><?php esc_html_e( 'Name', 'radical-socials' ); ?></label>
								<input
									name="rs_display_name"
									type="text"
									id="rs_display_name"
									value="<?php echo esc_attr( $display ); ?>"
									class="regular-text"
								/>
							</div>
							<div class="rs-field">
								<label for="rs_profile_handle"><?php esc_html_e( 'Handle', 'radical-socials' ); ?></label>
								<div class="rs-handle-input">
									<span aria-hidden="true">@</span>
									<input
										name="rs_profile_handle"
										type="text"
										id="rs_profile_handle"
										value="<?php echo esc_attr( $handle ); ?>"
										autocomplete="off"
									/>
								</div>
								<p class="description"><?php esc_html_e( 'Shown on your profile. This does not change your WordPress login.', 'radical-socials' ); ?></p>
							</div>
							<div class="rs-field">
								<label for="rs_profile_bio"><?php esc_html_e( 'Bio', 'radical-socials' ); ?></label>
								<textarea
									name="rs_profile_bio"
									id="rs_profile_bio"
									rows="4"
									maxlength="160"
									class="large-text"
								><?php echo esc_textarea( $bio ); ?></textarea>
							</div>
							<div class="rs-field">
								<label for="rs_profile_website"><?php esc_html_e( 'Website', 'radical-socials' ); ?></label>
								<input
									name="rs_profile_website"
									type="text"
									inputmode="url"
									id="rs_profile_website"
									value="<?php echo esc_attr( $website ); ?>"
									class="regular-text"
									placeholder="https://example.com"
								/>
							</div>
						</section>

						<?php if ( current_user_can( 'upload_files' ) ) : ?>
						<section class="rs-settings-panel">
							<h2><?php esc_html_e( 'Photos', 'radical-socials' ); ?></h2>
							<div class="rs-media-grid">
								<div
									class="rs-media-control"
									data-rs-media-control
									data-rs-media-target="avatar"
									data-empty-label="<?php esc_attr_e( 'Profile photo', 'radical-socials' ); ?>"
									data-add-text="<?php esc_attr_e( 'Add photo', 'radical-socials' ); ?>"
									data-change-text="<?php esc_attr_e( 'Change photo', 'radical-socials' ); ?>"
								>
									<div class="rs-media-preview rs-media-preview-avatar<?php echo $avatar_url ? ' has-image' : ''; ?>" data-rs-media-preview>
										<?php if ( $avatar_url ) : ?>
											<img src="<?php echo esc_url( $avatar_url ); ?>" alt="" />
										<?php else : ?>
											<span><?php esc_html_e( 'Profile photo', 'radical-socials' ); ?></span>
										<?php endif; ?>
									</div>
									<input type="hidden" name="rs_profile_avatar_id" value="<?php echo esc_attr( $avatar_id ?: '' ); ?>" data-rs-media-input>
									<div class="rs-media-actions">
										<button
											type="button"
											class="button"
											data-rs-media-open
											data-title="<?php esc_attr_e( 'Select profile photo', 'radical-socials' ); ?>"
											data-button="<?php esc_attr_e( 'Use this photo', 'radical-socials' ); ?>"
										>
											<?php echo $avatar_url ? esc_html__( 'Change photo', 'radical-socials' ) : esc_html__( 'Add photo', 'radical-socials' ); ?>
										</button>
										<button type="button" class="button button-link-delete<?php echo $avatar_url ? '' : ' hidden'; ?>" data-rs-media-remove>
											<?php esc_html_e( 'Remove', 'radical-socials' ); ?>
										</button>
									</div>
								</div>

								<div
									class="rs-media-control"
									data-rs-media-control
									data-rs-media-target="cover"
									data-empty-label="<?php esc_attr_e( 'Cover photo', 'radical-socials' ); ?>"
									data-add-text="<?php esc_attr_e( 'Add cover', 'radical-socials' ); ?>"
									data-change-text="<?php esc_attr_e( 'Change cover', 'radical-socials' ); ?>"
								>
									<div class="rs-media-preview rs-media-preview-cover<?php echo $banner_url ? ' has-image' : ''; ?>" data-rs-media-preview>
										<?php if ( $banner_url ) : ?>
											<img src="<?php echo esc_url( $banner_url ); ?>" alt="" />
										<?php else : ?>
											<span><?php esc_html_e( 'Cover photo', 'radical-socials' ); ?></span>
										<?php endif; ?>
									</div>
									<input type="hidden" name="rs_profile_banner_id" value="<?php echo esc_attr( $banner_id ?: '' ); ?>" data-rs-media-input>
									<div class="rs-media-actions">
										<button
											type="button"
											class="button"
											data-rs-media-open
											data-title="<?php esc_attr_e( 'Select cover photo', 'radical-socials' ); ?>"
											data-button="<?php esc_attr_e( 'Use this cover', 'radical-socials' ); ?>"
										>
											<?php echo $banner_url ? esc_html__( 'Change cover', 'radical-socials' ) : esc_html__( 'Add cover', 'radical-socials' ); ?>
										</button>
										<button type="button" class="button button-link-delete<?php echo $banner_url ? '' : ' hidden'; ?>" data-rs-media-remove>
											<?php esc_html_e( 'Remove', 'radical-socials' ); ?>
										</button>
									</div>
								</div>
							</div>
						</section>
						<?php endif; ?>

						<section class="rs-settings-panel">
							<h2><?php esc_html_e( 'Site', 'radical-socials' ); ?></h2>
							<div class="rs-field">
								<label for="blogname"><?php esc_html_e( 'Site title', 'radical-socials' ); ?></label>
								<input
									name="blogname"
									type="text"
									id="blogname"
									value="<?php echo esc_attr( get_option( 'blogname' ) ); ?>"
									class="regular-text"
								/>
							</div>

							<?php if ( current_user_can( 'upload_files' ) ) : ?>
							<div class="rs-field hide-if-no-js site-icon-section">
								<label><?php esc_html_e( 'Site logo', 'radical-socials' ); ?></label>
								<style>
								:root { --site-icon-url: url( '<?php echo esc_url( $logo_url ); ?>' ); }
								</style>

								<div id="site-icon-preview" class="<?php echo esc_attr( $classes_for_preview ); ?>">
									<div class="direction-wrap">
										<img id="app-icon-preview"
											src="<?php echo esc_url( $logo_url ); ?>"
											class="app-icon-preview"
											alt="" />
										<div class="site-icon-preview-browser">
											<svg role="img" aria-hidden="true" fill="none" xmlns="http://www.w3.org/2000/svg" class="browser-buttons"><path fill-rule="evenodd" clip-rule="evenodd" d="M0 20a6 6 0 1 1 12 0 6 6 0 0 1-12 0Zm18 0a6 6 0 1 1 12 0 6 6 0 0 1-12 0Zm24-6a6 6 0 1 0 0 12 6 6 0 0 0 0-12Z" /></svg>
											<div class="site-icon-preview-tab">
												<img id="browser-icon-preview"
													src="<?php echo esc_url( $logo_url ); ?>"
													class="browser-icon-preview"
													alt="" />
												<div class="site-icon-preview-site-title" aria-hidden="true"><?php bloginfo( 'name' ); ?></div>
												<svg role="img" aria-hidden="true" fill="none" xmlns="http://www.w3.org/2000/svg" class="close-button">
													<path d="M12 13.0607L15.7123 16.773L16.773 15.7123L13.0607 12L16.773 8.28772L15.7123 7.22706L12 10.9394L8.28771 7.22705L7.22705 8.28771L10.9394 12L7.22706 15.7123L8.28772 16.773L12 13.0607Z" />
												</svg>
											</div>
										</div>
									</div>
								</div>

								<input type="hidden" name="custom_logo" id="site_icon_hidden_field"
									value="<?php echo esc_attr( $logo_id ?: '' ); ?>">

								<div class="site-icon-action-buttons">
									<button
										type="button"
										id="choose-from-library-button"
										class="<?php echo esc_attr( $classes_for_button ); ?>"
										data-alt-classes="<?php echo esc_attr( $classes_for_alt ); ?>"
										data-size="512"
										data-choose-text="<?php esc_attr_e( 'Select Logo', 'radical-socials' ); ?>"
										data-update-text="<?php esc_attr_e( 'Change Logo', 'radical-socials' ); ?>"
										data-update="<?php esc_attr_e( 'Use this logo', 'radical-socials' ); ?>"
										data-state="<?php echo esc_attr( $has_logo ? '1' : '' ); ?>"
									>
										<?php echo $has_logo
											? esc_html__( 'Change Logo', 'radical-socials' )
											: esc_html__( 'Select Logo', 'radical-socials' ); ?>
									</button>
									<button
										id="js-remove-site-icon"
										type="button"
										<?php echo $has_logo
											? 'class="button button-secondary reset remove-site-icon"'
											: 'class="button button-secondary reset remove-site-icon hidden"'; ?>
									>
										<?php esc_html_e( 'Remove Logo', 'radical-socials' ); ?>
									</button>
								</div>
							</div>
							<?php endif; ?>
						</section>
					</div>
				</div>
				<?php submit_button(); ?>
			</form>

			<?php elseif ( 'following' === $active_tab ) : ?>

			<style>
			.rs-following-layout {
				display: grid;
				grid-template-columns: 2fr 3fr;
				gap: 32px;
				align-items: start;
				margin-top: 20px;
			}
			@media (max-width: 960px) {
				.rs-following-layout { grid-template-columns: 1fr; }
			}
			.rs-following-table { margin-top: 0; }
			.rs-following-table tbody tr:nth-child(even) td { background: #f6f7f7; }
			.rs-type-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
			.rs-type-rss         { background: #f0f6fc; color: #0073aa; }
			.rs-type-activitypub { background: #f3f0ff; color: #6b21a8; }
			.rs-type-wpcom       { background: #f0fff4; color: #166534; }
			.rs-category-tag { display: inline-block; margin: 1px 3px 1px 0; padding: 1px 7px; border-radius: 3px; font-size: 11px; background: #fef9e7; color: #7c5e00; border: 1px solid #f0d060; }
			.rs-error { color: #dc3232; }

			/* Signal-strength indicator. Three bars; CSS selects how many are active
			   and what colour, based on the parent's status modifier class. */
			.rs-health { display: inline-flex; cursor: help; line-height: 0; }
			.rs-health .rs-health-bar { fill: #dcdcde; transition: fill .15s; }
			.rs-health-ok   .rs-health-bar-1,
			.rs-health-ok   .rs-health-bar-2,
			.rs-health-ok   .rs-health-bar-3 { fill: #00a32a; }
			.rs-health-slow .rs-health-bar-1,
			.rs-health-slow .rs-health-bar-2 { fill: #dba617; }
			.rs-health-failed .rs-health-bar-1 { fill: #d63638; }
			.rs-health-untested .rs-health-bar { fill: #c3c4c7; }
			</style>

			<div class="rs-following-layout">

				<!-- Left column: controls -->
				<div>
					<?php
					$following_page    = get_page_by_path( 'following', OBJECT, 'page' );
					$following_url     = $following_page ? get_permalink( $following_page->ID ) : home_url( '/following/' );
					$following_public  = (bool) get_option( Radical_Socials_Following::PUBLIC_OPTION, false );
					$purge_on_uninstall = (bool) get_option( 'rs_purge_on_uninstall', false );
					?>
					<p class="description"><?php printf(
						/* translators: %s: link to the /following page */
						esc_html__( 'Configure what appears at %s. Each source type stacks on top of the last — connect more to see more.', 'radical-socials' ),
						'<a href="' . esc_url( $following_url ) . '" target="_blank" rel="noopener">' . esc_html( $following_url ) . '</a>'
					); ?></p>

					<form method="post" style="margin:16px 0 24px;padding:12px 14px;border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7">
						<?php wp_nonce_field( 'rs_following_privacy_save', 'rs_following_privacy_nonce' ); ?>
						<strong style="display:block;margin-bottom:6px"><?php esc_html_e( 'Privacy', 'radical-socials' ); ?></strong>
						<label style="display:flex;gap:8px;align-items:flex-start">
							<input type="checkbox" name="rs_following_public" value="1" <?php checked( $following_public ); ?> />
							<span>
								<?php esc_html_e( 'Allow logged-out visitors to see the Following page', 'radical-socials' ); ?>
								<br>
								<span class="description"><?php esc_html_e( 'When off, /following/ and its items return 404 for logged-out visitors, and the Following menu link is hidden for them.', 'radical-socials' ); ?></span>
							</span>
						</label>
						<label style="display:flex;gap:8px;align-items:flex-start;margin-top:10px">
							<input type="checkbox" name="rs_purge_on_uninstall" value="1" <?php checked( $purge_on_uninstall ); ?> />
							<span>
								<?php esc_html_e( 'Delete all data when the plugin is uninstalled', 'radical-socials' ); ?>
								<br>
								<span class="description"><?php esc_html_e( 'Off by default. When off, uninstalling removes only plugin settings; your imported feeds, favorites, and follows are preserved. Turn this on if you want a true wipe — note that your imported social-media archives (potentially your only copy) would also be deleted.', 'radical-socials' ); ?></span>
							</span>
						</label>
						<p style="margin:10px 0 0">
							<button type="submit" class="button button-secondary"><?php esc_html_e( 'Save', 'radical-socials' ); ?></button>
						</p>
					</form>

					<?php if ( class_exists( 'Radical_Socials_WPCOM_OAuth' ) && Radical_Socials_WPCOM_OAuth::is_configured() ) : ?>
					<p style="margin-top:12px">
						<?php if ( Radical_Socials_WPCOM_OAuth::is_connected() ) : ?>
							<?php esc_html_e( 'WP.com account connected.', 'radical-socials' ); ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=radical-socials-settings&rs_action=wpcom_disconnect' ), 'rs_wpcom_disconnect' ) ); ?>" class="button button-small button-secondary" style="margin-left:8px"><?php esc_html_e( 'Disconnect', 'radical-socials' ); ?></a>
						<?php else : ?>
							<a href="<?php echo esc_url( Radical_Socials_WPCOM_OAuth::connect_url() ); ?>" class="button button-primary"><?php esc_html_e( 'Connect WP.com Account', 'radical-socials' ); ?></a>
							<span class="description" style="margin-left:8px"><?php esc_html_e( 'Unlocks WP.com sites, Bluesky accounts, and the full WP.com Reader.', 'radical-socials' ); ?></span>
						<?php endif; ?>
					</p>
					<?php endif; ?>

					<?php $ap_active = class_exists( '\\Activitypub\\Collection\\Following' ); ?>
					<div style="margin-top:16px">
						<label for="rs-add-input"><strong><?php esc_html_e( 'Add feeds or accounts', 'radical-socials' ); ?></strong></label>
						<p class="description" style="margin-bottom:8px">
							<?php if ( $ap_active ) : ?>
								<?php esc_html_e( 'One per line. RSS/Atom URLs or ActivityPub handles (e.g. @someone@mastodon.social).', 'radical-socials' ); ?><br>
								<?php esc_html_e( 'ActivityPub supports: Mastodon, Pixelfed, Misskey, Pleroma, Peertube, Lemmy, Friendica, Hubzilla, and any ActivityPub-compatible account.', 'radical-socials' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'One per line. RSS/Atom feed URLs.', 'radical-socials' ); ?><br>
								<?php
								printf(
									/* translators: %s: link to the ActivityPub plugin install screen */
									esc_html__( 'Install the %s to also follow Mastodon, Pixelfed, and other Fediverse accounts.', 'radical-socials' ),
									'<a href="' . esc_url( self_admin_url( 'plugin-install.php?s=activitypub&tab=search&type=term' ) ) . '">' . esc_html__( 'ActivityPub plugin', 'radical-socials' ) . '</a>'
								);
								?>
							<?php endif; ?>
						</p>
						<textarea id="rs-add-input" rows="5" class="large-text" placeholder="<?php echo esc_attr( $ap_active ? "https://example.com/feed\n@someone@mastodon.social" : 'https://example.com/feed' ); ?>"></textarea>
						<p>
							<button id="rs-add-btn" type="button" class="button button-primary"><?php esc_html_e( 'Add', 'radical-socials' ); ?></button>
						</p>
						<div id="rs-add-progress" hidden style="margin-top:8px">
							<progress id="rs-add-progress-bar" value="0" max="100" style="width:100%;max-width:100%;display:block"></progress>
							<span id="rs-add-progress-text"></span>
						</div>
						<div id="rs-add-failures" hidden style="margin-top:8px"></div>
					</div>

					<?php if ( $ap_active ) : ?>
					<div style="margin-top:24px">
						<strong><?php esc_html_e( 'Import follows from an account', 'radical-socials' ); ?></strong>
						<p class="description" style="margin:4px 0 8px">
							<?php esc_html_e( 'Enter your Mastodon (or any ActivityPub) handle and we\'ll add everyone you follow as feeds.', 'radical-socials' ); ?><br>
							<?php esc_html_e( 'Note: your following list must be set to public in your account\'s privacy settings.', 'radical-socials' ); ?>
						</p>
						<input type="text" id="rs-import-account-input" class="regular-text" placeholder="@you@mastodon.social">
						<button id="rs-import-account-btn" type="button" class="button button-secondary">
							<?php esc_html_e( 'Import follows', 'radical-socials' ); ?>
						</button>
						<div id="rs-import-account-progress" hidden style="margin-top:8px">
							<progress id="rs-import-account-bar" value="0" max="100" style="width:100%;max-width:100%;display:block"></progress>
							<span id="rs-import-account-text"></span>
						</div>
					</div>
					<?php endif; ?>

					<div style="margin-top:24px">
						<strong><?php esc_html_e( 'Import OPML', 'radical-socials' ); ?></strong>
						<p class="description" style="margin:4px 0 8px">
							<?php esc_html_e( 'Adds new feeds and backfills titles, URLs, and categories for any feeds already in your list that are missing that information.', 'radical-socials' ); ?>
						</p>
						<input type="file" id="rs-opml-file" accept=".opml,.xml" style="margin-bottom:8px;display:block">
						<button id="rs-opml-import-btn" type="button" class="button button-secondary">
							<?php esc_html_e( 'Import OPML', 'radical-socials' ); ?>
						</button>
					</div>

					<div style="margin-top:16px">
						<strong><?php esc_html_e( 'Export OPML', 'radical-socials' ); ?></strong>
						<p class="description" style="margin:4px 0 8px">
							<?php esc_html_e( 'Downloads all your RSS subscriptions as an OPML file, including titles, feed URLs, site URLs, and any categories.', 'radical-socials' ); ?>
						</p>
						<a id="rs-opml-export-link" class="button button-secondary" download>
							<?php esc_html_e( 'Export OPML', 'radical-socials' ); ?>
						</a>
					</div>
				</div>

				<!-- Right column: feeds table -->
				<div>
					<h3 id="rs-feeds-heading" style="margin-top:0"><?php esc_html_e( 'Feeds', 'radical-socials' ); ?></h3>
					<div id="rs-following-table-wrap"></div>
				</div>

			</div>

			<?php elseif ( 'diagnostics' === $active_tab ) : ?>
				<?php self::render_diagnostics_tab(); ?>
			<?php endif; ?>
			</div><!-- .rs-settings -->
		</div>
		<?php
	}

	/**
	 * Return per-step completion flags for the onboarding wizard. Shared
	 * between render_welcome_tab() and the Plugins-page action link so the
	 * "is the user done?" check has exactly one definition.
	 *
	 * @return array{ap:bool, subs:bool, post:bool, all:bool}
	 */
	public static function wizard_status(): array {
		$ap_active = class_exists( '\\Activitypub\\Collection\\Following' );

		$rss_count   = count( (array) get_option( 'rs_rss_subscriptions', [] ) );
		$ap_count    = $ap_active
			? (int) ( new WP_Query( [ 'post_type' => 'ap_actor', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => false ] ) )->found_posts
			: 0;
		$wpcom_conn  = class_exists( 'Radical_Socials_WPCOM_OAuth' ) && Radical_Socials_WPCOM_OAuth::is_connected();
		$sub_count   = $rss_count + $ap_count + ( $wpcom_conn ? 1 : 0 );

		$post_count = (int) count_user_posts( get_current_user_id(), 'post', true );

		return [
			'ap'   => $ap_active,
			'subs' => $sub_count > 0,
			'post' => $post_count > 0,
			'all'  => $ap_active && $sub_count > 0 && $post_count > 0,
		];
	}

	/**
	 * Onboarding wizard. Three sequential steps: install ActivityPub +
	 * confirm handle, add accounts to follow, publish first post. Each
	 * step's status is derived from real state (plugin presence, current
	 * subscription counts, user's published post count), so admins can
	 * leave and return mid-flow and the wizard reflects what's left.
	 */
	private static function render_welcome_tab(): void {
		$status     = self::wizard_status();
		$ap_active  = $status['ap'];
		$step1_done = $status['ap'];
		$step2_done = $status['subs'];
		$step3_done = $status['post'];

		// The currently "active" step is the first incomplete one. Used to
		// colour its marker blue so the user knows where to look next.
		$active_step = ! $step1_done ? 1 : ( ! $step2_done ? 2 : ( ! $step3_done ? 3 : 0 ) );

		$step_class = function ( int $n ) use ( $active_step, $step1_done, $step2_done, $step3_done ): string {
			$done = [ 1 => $step1_done, 2 => $step2_done, 3 => $step3_done ][ $n ];
			if ( $done ) {
				return 'rs-welcome-step is-done';
			}
			if ( $active_step === $n ) {
				return 'rs-welcome-step is-active';
			}
			return 'rs-welcome-step';
		};

		$step_marker = function ( int $n ) use ( $step1_done, $step2_done, $step3_done ): string {
			$done = [ 1 => $step1_done, 2 => $step2_done, 3 => $step3_done ][ $n ];
			return $done ? '✓' : (string) $n;
		};

		// Surface admin-post handler results.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ap_installed   = isset( $_GET['rs_ap_installed'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ap_error       = isset( $_GET['rs_ap_install_error'] ) ? sanitize_text_field( wp_unslash( $_GET['rs_ap_install_error'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$handle_saved   = isset( $_GET['rs_handle_saved'] );
		?>
		<p class="rs-welcome-intro">
			<?php esc_html_e( 'A few quick steps and your site is ready to read, post, and federate. You can come back to this tab any time — completed steps will stay checked.', 'radical-socials' ); ?>
		</p>

		<?php if ( $ap_installed ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'ActivityPub installed and activated.', 'radical-socials' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $ap_error ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php
				printf(
					/* translators: %s: error message from the WP plugin installer */
					esc_html__( 'Could not install ActivityPub: %s', 'radical-socials' ),
					esc_html( $ap_error )
				);
			?></p></div>
		<?php endif; ?>
		<?php if ( $handle_saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Handle saved.', 'radical-socials' ); ?></p></div>
		<?php endif; ?>

		<ol class="rs-welcome-steps">

			<!-- Step 1: Install ActivityPub + handle -->
			<li class="<?php echo esc_attr( $step_class( 1 ) ); ?>">
				<div class="rs-welcome-step__marker"><?php echo esc_html( $step_marker( 1 ) ); ?></div>
				<div class="rs-welcome-step__body">
					<h2><?php esc_html_e( 'Connect to the Fediverse', 'radical-socials' ); ?></h2>
					<?php if ( ! $ap_active ) : ?>
						<p><?php esc_html_e( 'Install the ActivityPub plugin so you can follow Mastodon / Pixelfed / Peertube accounts and so your own posts reach the Fediverse. This is one click — we\'ll install it and bring you right back here.', 'radical-socials' ); ?></p>
						<p class="description" style="margin:-4px 0 12px">
							<a href="https://en.wikipedia.org/wiki/Fediverse" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'What is the Fediverse?', 'radical-socials' ); ?></a>
						</p>
						<div class="rs-welcome-step__actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0">
								<input type="hidden" name="action" value="rs_install_activitypub" />
								<?php wp_nonce_field( 'rs_install_activitypub' ); ?>
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Install &amp; activate ActivityPub', 'radical-socials' ); ?></button>
							</form>
						</div>
					<?php else :
						$blog_identifier = get_option( 'activitypub_blog_identifier', '' );
						if ( '' === $blog_identifier ) {
							$blog_identifier = sanitize_title( get_bloginfo( 'name' ) ) ?: 'site';
						}
						$host = wp_parse_url( home_url(), PHP_URL_HOST );
						?>
						<p><?php esc_html_e( 'ActivityPub is active. This is the @handle that other Fediverse accounts will use to follow you:', 'radical-socials' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rs-welcome-step__actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
							<input type="hidden" name="action" value="rs_save_handle" />
							<?php wp_nonce_field( 'rs_save_handle' ); ?>
							<span class="rs-welcome-handle-preview">@<input
								type="text"
								name="rs_blog_identifier"
								value="<?php echo esc_attr( $blog_identifier ); ?>"
								pattern="[A-Za-z0-9_\-]+"
								maxlength="40"
								style="border:0;background:transparent;width:8em;font-family:inherit"
							/>@<?php echo esc_html( $host ); ?></span>
							<button type="submit" class="button button-secondary"><?php esc_html_e( 'Save handle', 'radical-socials' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</li>

			<!-- Step 2: Add accounts to follow -->
			<li class="<?php echo esc_attr( $step_class( 2 ) ); ?>">
				<div class="rs-welcome-step__marker"><?php echo esc_html( $step_marker( 2 ) ); ?></div>
				<div class="rs-welcome-step__body">
					<h2><?php esc_html_e( 'Add accounts to follow', 'radical-socials' ); ?></h2>
					<p><?php esc_html_e( 'Pick whichever path matches your existing feeds. You can use more than one — they all land in the same unified timeline.', 'radical-socials' ); ?></p>

					<div class="rs-welcome-step__addbox">
						<strong><?php esc_html_e( 'Paste feeds or handles', 'radical-socials' ); ?></strong>
						<p class="description" style="margin:4px 0 8px">
							<?php if ( $ap_active ) : ?>
								<?php esc_html_e( 'One per line. RSS/Atom URLs or ActivityPub handles (e.g. @someone@mastodon.social).', 'radical-socials' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'One per line. RSS/Atom feed URLs.', 'radical-socials' ); ?>
							<?php endif; ?>
						</p>
						<textarea id="rs-add-input" rows="4" class="large-text" placeholder="<?php echo esc_attr( $ap_active ? "https://example.com/feed\n@someone@mastodon.social" : 'https://example.com/feed' ); ?>"></textarea>
						<p style="margin-top:6px">
							<button id="rs-add-btn" type="button" class="button button-primary"><?php esc_html_e( 'Add', 'radical-socials' ); ?></button>
						</p>
						<div id="rs-add-progress" hidden style="margin-top:8px">
							<progress id="rs-add-progress-bar" value="0" max="100" style="width:100%;max-width:100%;display:block"></progress>
							<span id="rs-add-progress-text"></span>
						</div>
						<div id="rs-add-failures" hidden style="margin-top:8px"></div>
					</div>

					<?php if ( $ap_active ) : ?>
					<div class="rs-welcome-step__addbox">
						<strong><?php esc_html_e( 'Import from a Mastodon (or any ActivityPub) account', 'radical-socials' ); ?></strong>
						<p class="description" style="margin:4px 0 8px">
							<?php esc_html_e( 'Pulls the account\'s public following list and adds each one as a feed. The account\'s "Show following" privacy setting must be on.', 'radical-socials' ); ?>
						</p>
						<input type="text" id="rs-import-account-input" class="regular-text" placeholder="@you@mastodon.social" />
						<button id="rs-import-account-btn" type="button" class="button button-secondary"><?php esc_html_e( 'Import follows', 'radical-socials' ); ?></button>
						<div id="rs-import-account-progress" hidden style="margin-top:8px">
							<progress id="rs-import-account-bar" value="0" max="100" style="width:100%;max-width:100%;display:block"></progress>
							<span id="rs-import-account-text"></span>
						</div>
					</div>
					<?php endif; ?>

					<div class="rs-welcome-step__addbox">
						<strong><?php esc_html_e( 'Import an OPML file', 'radical-socials' ); ?></strong>
						<p class="description" style="margin:4px 0 8px">
							<?php esc_html_e( 'If you\'re moving from another feed reader, export your subscriptions there and drop the file here.', 'radical-socials' ); ?>
						</p>
						<input type="file" id="rs-opml-file" accept=".opml,.xml" style="margin-bottom:8px;display:block" />
						<button id="rs-opml-import-btn" type="button" class="button button-secondary"><?php esc_html_e( 'Import OPML', 'radical-socials' ); ?></button>
					</div>

					<?php if ( class_exists( 'Radical_Socials_WPCOM_OAuth' ) && Radical_Socials_WPCOM_OAuth::is_configured() ) : ?>
					<div class="rs-welcome-step__addbox">
						<strong><?php esc_html_e( 'Connect your WordPress.com account', 'radical-socials' ); ?></strong>
						<p class="description" style="margin:4px 0 8px">
							<?php esc_html_e( 'We\'ll pull the sites you follow in the WP.com Reader and add them as feeds. Tokens stay on this site only.', 'radical-socials' ); ?>
						</p>
						<?php if ( Radical_Socials_WPCOM_OAuth::is_connected() ) : ?>
							<span style="color:#00a32a">✓ <?php esc_html_e( 'Connected.', 'radical-socials' ); ?></span>
						<?php else : ?>
							<a href="<?php echo esc_url( Radical_Socials_WPCOM_OAuth::connect_url() ); ?>" class="button button-secondary"><?php esc_html_e( 'Connect WordPress.com', 'radical-socials' ); ?></a>
						<?php endif; ?>
					</div>
					<?php endif; ?>

					<div class="rs-welcome-step__actions" style="margin-top:14px">
						<a href="#rs-welcome-step-3" class="button button-link"><?php esc_html_e( 'Skip for now', 'radical-socials' ); ?></a>
					</div>
				</div>
			</li>

			<!-- Step 3: First post -->
			<li id="rs-welcome-step-3" class="<?php echo esc_attr( $step_class( 3 ) ); ?>">
				<div class="rs-welcome-step__marker"><?php echo esc_html( $step_marker( 3 ) ); ?></div>
				<div class="rs-welcome-step__body">
					<h2><?php esc_html_e( 'Post your first post', 'radical-socials' ); ?></h2>
					<p><?php esc_html_e( 'Your home page is also your editor — there\'s a composer right at the top. Write anything; once you publish, it appears in your own timeline and (with ActivityPub on) lands in your Fediverse followers\' feeds.', 'radical-socials' ); ?></p>
					<div class="rs-welcome-step__actions">
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Go to my home page', 'radical-socials' ); ?></a>
					</div>
				</div>
			</li>

		</ol>
		<?php
	}

	/**
	 * admin-post handler: install + activate the ActivityPub plugin, then
	 * redirect back to our Welcome wizard. Capability + nonce gated.
	 */
	public static function handle_install_activitypub(): void {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to install plugins.', 'radical-socials' ) );
		}
		check_admin_referer( 'rs_install_activitypub' );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$back = static function ( string $err = '' ): void {
			$url = admin_url( 'admin.php?page=radical-socials-settings&tab=welcome' );
			if ( $err ) {
				$url = add_query_arg( 'rs_ap_install_error', rawurlencode( $err ), $url );
			} else {
				$url = add_query_arg( 'rs_ap_installed', 1, $url );
			}
			wp_safe_redirect( $url );
			exit;
		};

		$plugin_file = 'activitypub/activitypub.php';

		// Already installed? Just activate.
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			$api = plugins_api( 'plugin_information', [
				'slug'   => 'activitypub',
				'fields' => [ 'sections' => false, 'banners' => false, 'reviews' => false ],
			] );
			if ( is_wp_error( $api ) ) {
				$back( $api->get_error_message() );
			}

			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $api->download_link );

			if ( is_wp_error( $result ) ) {
				$back( $result->get_error_message() );
			}
			if ( ! $result ) {
				$messages = $skin->get_error_messages();
				$back( $messages ? implode( '; ', $messages ) : __( 'Installer returned no result.', 'radical-socials' ) );
			}
		}

		if ( ! is_plugin_active( $plugin_file ) ) {
			// ActivityPub adds its own `activated_plugin` hook
			// (`Activitypub\activation_redirect`) that wp_safe_redirect()s to
			// its own welcome page and exits — which would short-circuit
			// our own redirect back to the wizard. AP's main file is
			// `include_once`'d inside activate_plugin() before any actions
			// fire, so by the time `activated_plugin` runs, AP's hook is
			// already registered. Race ahead at priority 1 and pull AP's
			// callback off the action list before it gets a chance to run.
			$strip_ap_redirect = static function ( $activated_plugin ) use ( $plugin_file ): void {
				if ( $activated_plugin === $plugin_file ) {
					remove_action( 'activated_plugin', 'Activitypub\\activation_redirect' );
				}
			};
			add_action( 'activated_plugin', $strip_ap_redirect, 1 );

			$activated = activate_plugin( $plugin_file );

			remove_action( 'activated_plugin', $strip_ap_redirect, 1 );

			if ( is_wp_error( $activated ) ) {
				$back( $activated->get_error_message() );
			}
		}

		$back();
	}

	/**
	 * admin-post handler: save the AP blog actor handle from step 1.
	 */
	public static function handle_save_handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'radical-socials' ) );
		}
		check_admin_referer( 'rs_save_handle' );

		$candidate = isset( $_POST['rs_blog_identifier'] ) ? sanitize_user( wp_unslash( $_POST['rs_blog_identifier'] ), true ) : '';
		if ( '' !== $candidate ) {
			update_option( 'activitypub_blog_identifier', $candidate );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=radical-socials-settings&tab=welcome&rs_handle_saved=1' ) );
		exit;
	}

	private static function render_diagnostics_tab(): void {
		$lock          = get_transient( Radical_Socials_Following::REFRESH_LOCK );
		$last          = (int) get_option( 'rs_last_feed_fetch', 0 );
		$next_ts       = wp_next_scheduled( Radical_Socials_Following::FETCH_HOOK );
		$schedule_name = $next_ts ? wp_get_schedule( Radical_Socials_Following::FETCH_HOOK ) : '';
		$rss_count     = count( (array) get_option( 'rs_rss_subscriptions', [] ) );
		$ap_count      = count( get_posts( [ 'post_type' => 'ap_actor', 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'any' ] ) );
		$item_total    = (int) wp_count_posts( 'rs_feed_item' )->publish;

		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$mem_bytes     = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$exec_time     = (int) ini_get( 'max_execution_time' );

		// Surface single-shot post-action messages stored in short-lived transients.
		$result   = isset( $_GET['rs_diag_result'] ) ? sanitize_text_field( wp_unslash( $_GET['rs_diag_result'] ) ) : '';
		$elapsed  = get_transient( 'rs_diag_fetch_elapsed' );
		$err_msg  = get_transient( 'rs_diag_fetch_error' );
		$test     = get_transient( 'rs_diag_test_result' );
		if ( $elapsed !== false ) delete_transient( 'rs_diag_fetch_elapsed' );
		if ( $err_msg !== false ) delete_transient( 'rs_diag_fetch_error' );
		if ( $test    !== false ) delete_transient( 'rs_diag_test_result' );

		// Build the warning list.
		$warnings = [];
		if ( $last === 0 && ( $rss_count + $ap_count ) > 0 ) {
			$warnings[] = [ 'level' => 'error', 'msg' => __( 'Feeds have never refreshed despite having active subscriptions. Use "Run fetch now" below to do a one-time inline run.', 'radical-socials' ) ];
		} elseif ( $last > 0 && ( time() - $last ) > HOUR_IN_SECONDS ) {
			$warnings[] = [ 'level' => 'warning', 'msg' => __( 'The last successful refresh was over an hour ago. Cron may not be running on your host.', 'radical-socials' ) ];
		}
		if ( $cron_disabled ) {
			$warnings[] = [
				'level' => 'info',
				'msg'   => __( 'WordPress\'s built-in cron is disabled on this site (DISABLE_WP_CRON = true). The 15-minute background refresh only runs if your host has a real cron job configured to hit wp-cron.php. Hostinger users: set this up in hPanel → Cron Jobs with the command shown below.', 'radical-socials' ),
			];
		}
		if ( Radical_Socials_Following::REFRESH_LOCK_QUEUED === $lock ) {
			$warnings[] = [ 'level' => 'warning', 'msg' => __( 'A refresh is currently queued. If this state persists for more than a couple of minutes, cron isn\'t firing — clear the lock and try "Run fetch now".', 'radical-socials' ) ];
		}
		if ( $mem_bytes > 0 && $mem_bytes < 128 * MB_IN_BYTES ) {
			$warnings[] = [ 'level' => 'warning', 'msg' => sprintf( __( 'PHP memory_limit is %s. Polling 100+ feeds may run out of memory. Raise it to at least 128M.', 'radical-socials' ), ini_get( 'memory_limit' ) ) ];
		}
		if ( $exec_time > 0 && $exec_time < 60 ) {
			$warnings[] = [ 'level' => 'warning', 'msg' => sprintf( __( 'PHP max_execution_time is %ds. A full refresh of many feeds may not finish before the host kills it.', 'radical-socials' ), $exec_time ) ];
		}
		if ( ! function_exists( 'Activitypub\follow' ) ) {
			$warnings[] = [
				'level' => 'info',
				'msg'   => sprintf(
					/* translators: %s: link to the ActivityPub plugin install screen */
					__( 'The ActivityPub plugin is not active. RSS and WP.com Reader follows work without it; install %s to also follow Fediverse accounts and federate your own posts.', 'radical-socials' ),
					'<a href="' . esc_url( self_admin_url( 'plugin-install.php?s=activitypub&tab=search&type=term' ) ) . '">' . esc_html__( 'ActivityPub', 'radical-socials' ) . '</a>'
				),
			];
		}

		// Action result notice.
		$result_notice = self::diagnostics_result_notice( $result, $elapsed, $err_msg, $test );

		$nonce = wp_create_nonce( 'rs_diagnostics_action' );
		?>
		<div style="margin-top:20px;max-width:840px">
			<?php echo $result_notice; // already escaped inside the helper ?>

			<?php if ( ! empty( $warnings ) ) : ?>
				<div style="margin:16px 0">
					<?php foreach ( $warnings as $w ) :
						$colour = [
							'error'   => '#dc3232',
							'warning' => '#dba617',
							'info'    => '#2271b1',
						][ $w['level'] ];
						?>
						<div style="border-left:4px solid <?php echo esc_attr( $colour ); ?>;background:#fff;padding:10px 14px;margin:6px 0;box-shadow:0 1px 1px rgba(0,0,0,.04)">
							<?php echo wp_kses( $w['msg'], [ 'a' => [ 'href' => true ], 'strong' => [], 'em' => [], 'code' => [] ] ); ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<h2 style="margin-top:24px"><?php esc_html_e( 'Feed refresh status', 'radical-socials' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Last successful refresh', 'radical-socials' ); ?></th>
					<td><?php
						if ( $last === 0 ) {
							esc_html_e( 'Never', 'radical-socials' );
						} else {
							echo esc_html( sprintf(
								/* translators: 1: date 2: time-diff */
								__( '%1$s (%2$s ago)', 'radical-socials' ),
								wp_date( 'Y-m-d H:i:s', $last ),
								human_time_diff( $last, time() )
							) );
						}
					?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Refresh lock', 'radical-socials' ); ?></th>
					<td><code><?php echo $lock === false ? esc_html__( '(none)', 'radical-socials' ) : esc_html( (string) $lock ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Next scheduled refresh', 'radical-socials' ); ?></th>
					<td><?php
						if ( ! $next_ts ) {
							esc_html_e( 'Not scheduled', 'radical-socials' );
						} else {
							echo esc_html( sprintf(
								/* translators: 1: date 2: schedule 3: diff */
								__( '%1$s — schedule %2$s (in %3$s)', 'radical-socials' ),
								wp_date( 'Y-m-d H:i:s', $next_ts ),
								$schedule_name,
								human_time_diff( time(), $next_ts )
							) );
						}
					?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Subscriptions', 'radical-socials' ); ?></th>
					<td><?php
						if ( function_exists( 'Activitypub\\follow' ) ) {
							echo esc_html( sprintf(
								/* translators: 1: RSS count 2: AP count */
								__( '%1$d RSS, %2$d ActivityPub', 'radical-socials' ),
								$rss_count,
								$ap_count
							) );
						} else {
							echo esc_html( sprintf(
								/* translators: %d: RSS count */
								_n( '%d RSS feed', '%d RSS feeds', $rss_count, 'radical-socials' ),
								$rss_count
							) );
						}
					?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Feed items stored', 'radical-socials' ); ?></th>
					<td><?php echo esc_html( (string) $item_total ); ?></td>
				</tr>
			</table>

			<h2 style="margin-top:24px"><?php esc_html_e( 'Actions', 'radical-socials' ); ?></h2>
			<p class="description"><?php esc_html_e( 'When cron isn\'t firing, use these manually.', 'radical-socials' ); ?></p>

			<form method="post" style="display:inline-block;margin-right:8px">
				<input type="hidden" name="rs_diagnostics_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
				<input type="hidden" name="rs_diag" value="queue_fetch" />
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Queue a refresh', 'radical-socials' ); ?></button>
			</form>

			<form method="post" style="display:inline-block;margin-right:8px"
				  onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = <?php echo wp_json_encode( __( 'Fetching… this may take up to 10 minutes', 'radical-socials' ) ); ?>;">
				<input type="hidden" name="rs_diagnostics_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
				<input type="hidden" name="rs_diag" value="run_fetch" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Run fetch now (inline)', 'radical-socials' ); ?></button>
			</form>

			<form method="post" style="display:inline-block;margin-right:8px">
				<input type="hidden" name="rs_diagnostics_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
				<input type="hidden" name="rs_diag" value="clear_lock" />
				<button type="submit" class="button button-secondary"<?php disabled( $lock === false ); ?>><?php esc_html_e( 'Clear refresh lock', 'radical-socials' ); ?></button>
			</form>

			<form method="post" style="display:inline-block;margin-right:8px">
				<input type="hidden" name="rs_diagnostics_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
				<input type="hidden" name="rs_diag" value="test_feed" />
				<button type="submit" class="button button-secondary"<?php disabled( 0 === $rss_count ); ?>><?php esc_html_e( 'Test one feed', 'radical-socials' ); ?></button>
			</form>

			<?php
			$feed_test    = (array) get_transient( 'rs_diag_feed_test_results' );
			$feed_rows    = isset( $feed_test['rows'] ) && is_array( $feed_test['rows'] ) ? $feed_test['rows'] : [];
			$feed_total   = isset( $feed_test['total_targets'] ) ? (int) $feed_test['total_targets'] : ( $rss_count + $ap_count );
			$feed_tested  = count( $feed_rows );
			$feed_remaining = max( 0, $feed_total - $feed_tested );
			?>
			<form method="post" style="display:inline-block;margin-right:8px">
				<input type="hidden" name="rs_diagnostics_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
				<input type="hidden" name="rs_diag" value="test_feeds" />
				<button type="submit" class="button button-secondary"<?php disabled( 0 === ( $rss_count + $ap_count ) || 0 === $feed_remaining ); ?>>
					<?php
					if ( 0 === $feed_tested ) {
						esc_html_e( 'Test all feeds', 'radical-socials' );
					} else {
						printf(
							/* translators: 1: tested count, 2: total target count */
							esc_html__( 'Test next batch (%1$d / %2$d tested)', 'radical-socials' ),
							$feed_tested,
							$feed_total
						);
					}
					?>
				</button>
			</form>

			<?php if ( $feed_tested > 0 ) : ?>
				<form method="post" style="display:inline-block">
					<input type="hidden" name="rs_diagnostics_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
					<input type="hidden" name="rs_diag" value="reset_feed_test" />
					<button type="submit" class="button-link" style="color:#888"><?php esc_html_e( 'Reset feed-test results', 'radical-socials' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( $feed_tested > 0 ) :
				$bucket    = [ 'ok' => 0, 'slow' => 0, 'error' => 0 ];
				$by_type   = [ 'rss' => 0, 'activitypub' => 0 ];
				$by_type_total = [
					'rss'         => $rss_count,
					'activitypub' => $ap_count,
				];
				foreach ( $feed_rows as $row ) {
					if ( 200 === ( $row['status'] ?? 0 ) ) {
						$bucket[ ( $row['elapsed_ms'] ?? 0 ) > 3000 ? 'slow' : 'ok' ]++;
					} else {
						$bucket['error']++;
					}
					$row_type = (string) ( $row['type'] ?? '' );
					if ( isset( $by_type[ $row_type ] ) ) {
						$by_type[ $row_type ]++;
					}
				}
				// Sort by status (errors first) then by elapsed_ms desc.
				usort( $feed_rows, static function ( $a, $b ) {
					$a_err = ( $a['status'] ?? 0 ) !== 200 ? 0 : 1;
					$b_err = ( $b['status'] ?? 0 ) !== 200 ? 0 : 1;
					if ( $a_err !== $b_err ) {
						return $a_err - $b_err;
					}
					return ( $b['elapsed_ms'] ?? 0 ) - ( $a['elapsed_ms'] ?? 0 );
				} );
				?>
				<h2 style="margin-top:32px"><?php esc_html_e( 'Feed test results', 'radical-socials' ); ?></h2>
				<p>
					<span style="color:#0a7b3f"><?php printf( esc_html__( '%d OK', 'radical-socials' ), $bucket['ok'] ); ?></span>
					&nbsp;·&nbsp;
					<span style="color:#dba617"><?php printf( esc_html__( '%d slow (>3s)', 'radical-socials' ), $bucket['slow'] ); ?></span>
					&nbsp;·&nbsp;
					<span style="color:#dc3232"><?php printf( esc_html__( '%d failed', 'radical-socials' ), $bucket['error'] ); ?></span>
					<?php if ( ! empty( $feed_test['last_run'] ) ) : ?>
						&nbsp;·&nbsp;
						<span class="description"><?php
							printf(
								/* translators: %s: time-diff */
								esc_html__( 'last batch %s ago', 'radical-socials' ),
								esc_html( human_time_diff( (int) $feed_test['last_run'], time() ) )
							);
						?></span>
					<?php endif; ?>
				</p>
				<p class="description" style="margin-top:-6px">
					<?php
					printf(
						/* translators: 1: RSS tested, 2: RSS total, 3: AP tested, 4: AP total */
						esc_html__( '%1$d / %2$d RSS · %3$d / %4$d ActivityPub tested', 'radical-socials' ),
						$by_type['rss'],
						$by_type_total['rss'],
						$by_type['activitypub'],
						$by_type_total['activitypub']
					);
					?>
				</p>

				<table class="widefat striped" style="max-width:840px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Status', 'radical-socials' ); ?></th>
							<th><?php esc_html_e( 'Time', 'radical-socials' ); ?></th>
							<th><?php esc_html_e( 'Bytes', 'radical-socials' ); ?></th>
							<th><?php esc_html_e( 'Type', 'radical-socials' ); ?></th>
							<th><?php esc_html_e( 'URL / error', 'radical-socials' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $feed_rows as $row ) :
							$status = (int) ( $row['status'] ?? 0 );
							$elapsed = (int) ( $row['elapsed_ms'] ?? 0 );
							if ( 200 === $status ) {
								$colour = $elapsed > 3000 ? '#dba617' : '#0a7b3f';
								$label  = '200';
							} elseif ( $status > 0 ) {
								$colour = '#dc3232';
								$label  = (string) $status;
							} else {
								$colour = '#dc3232';
								$label  = '✘';
							}
							?>
							<tr>
								<td style="color:<?php echo esc_attr( $colour ); ?>;font-weight:600;white-space:nowrap"><?php echo esc_html( $label ); ?></td>
								<td><?php echo esc_html( $elapsed . ' ms' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) ( $row['bytes'] ?? 0 ) ) ); ?></td>
								<td><code style="font-size:11px"><?php echo esc_html( (string) ( $row['type'] ?? '' ) ); ?></code></td>
								<td style="word-break:break-all">
									<a href="<?php echo esc_url( (string) ( $row['url'] ?? '' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) ( $row['url'] ?? '' ) ); ?></a>
									<?php if ( ! empty( $row['error'] ) ) : ?>
										<br><code style="color:#dc3232;font-size:11px"><?php echo esc_html( (string) $row['error'] ); ?></code>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 style="margin-top:32px"><?php esc_html_e( 'Hosting', 'radical-socials' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">DISABLE_WP_CRON</th>
					<td><code><?php echo $cron_disabled ? 'true' : 'false'; ?></code>
						<?php if ( $cron_disabled ) : ?>
							<p class="description"><?php esc_html_e( 'WordPress will not auto-fire scheduled events on page visits. A real cron job on your host must call wp-cron.php instead. Recommended command:', 'radical-socials' ); ?></p>
							<p><code style="display:block;padding:8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px">wget -q -O - <?php echo esc_url( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null 2&gt;&amp;1</code></p>
							<p class="description"><?php esc_html_e( 'Schedule this every 15 minutes (cron expression: */15 * * * *).', 'radical-socials' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">memory_limit</th>
					<td><code><?php echo esc_html( ini_get( 'memory_limit' ) ); ?></code></td>
				</tr>
				<tr>
					<th scope="row">max_execution_time</th>
					<td><code><?php echo esc_html( (string) $exec_time ); ?>s</code></td>
				</tr>
				<tr>
					<th scope="row">PHP version</th>
					<td><code><?php echo esc_html( PHP_VERSION ); ?></code></td>
				</tr>
				<tr>
					<th scope="row">WP version</th>
					<td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
				</tr>
				<tr>
					<th scope="row">ActivityPub plugin</th>
					<td><?php echo function_exists( 'Activitypub\follow' ) ? '<span style="color:#0a7b3f">✓ ' . esc_html__( 'active', 'radical-socials' ) . '</span>' : '<span style="color:#dc3232">✘ ' . esc_html__( 'not active', 'radical-socials' ) . '</span>'; ?></td>
				</tr>
			</table>
		</div>
		<?php
	}

	private static function diagnostics_result_notice( string $result, $elapsed, $err_msg, $test ): string {
		if ( '' === $result ) {
			return '';
		}

		$class = 'notice notice-success is-dismissible';
		$body  = '';

		switch ( $result ) {
			case 'fetch_ran':
				$body = sprintf(
					/* translators: %s: elapsed seconds */
					esc_html__( 'Refresh completed in %ss.', 'radical-socials' ),
					esc_html( (string) $elapsed )
				);
				break;
			case 'fetch_error':
				$class = 'notice notice-error is-dismissible';
				$body  = esc_html__( 'Inline refresh threw an exception:', 'radical-socials' ) . ' <code>' . esc_html( (string) $err_msg ) . '</code>';
				break;
			case 'queued':
				$body = esc_html__( 'Refresh queued. Watch the "Last successful refresh" timestamp — it should update within a minute or two.', 'radical-socials' );
				break;
			case 'queue_failed':
				$class = 'notice notice-warning is-dismissible';
				$body  = esc_html__( 'Refresh could not be queued (another fetch is already running).', 'radical-socials' );
				break;
			case 'lock_cleared':
				$body = esc_html__( 'Refresh lock cleared.', 'radical-socials' );
				break;
			case 'no_subs':
				$class = 'notice notice-warning is-dismissible';
				$body  = esc_html__( 'No RSS subscriptions to test against.', 'radical-socials' );
				break;
			case 'feed_test_batch':
				$body = esc_html__( 'Tested a batch of feeds — see the results table below. Click again to test the next batch.', 'radical-socials' );
				break;
			case 'feed_test_empty':
				$class = 'notice notice-info is-dismissible';
				$body  = esc_html__( 'Every subscription has already been tested. Use "Reset feed-test results" to start over.', 'radical-socials' );
				break;
			case 'feed_test_reset':
				$body = esc_html__( 'Feed-test results cleared.', 'radical-socials' );
				break;
			case 'tested':
				if ( is_array( $test ) && ! empty( $test['ok'] ) ) {
					$body = sprintf(
						/* translators: 1: URL 2: HTTP code 3: bytes */
						esc_html__( 'Fetched %1$s — HTTP %2$d, %3$d bytes.', 'radical-socials' ),
						'<code>' . esc_html( $test['url'] ) . '</code>',
						(int) $test['code'],
						(int) $test['bytes']
					);
				} else {
					$class = 'notice notice-error is-dismissible';
					$body  = sprintf(
						/* translators: 1: URL 2: error message */
						esc_html__( 'Could not fetch %1$s — %2$s', 'radical-socials' ),
						'<code>' . esc_html( (string) ( $test['url'] ?? '' ) ) . '</code>',
						'<code>' . esc_html( (string) ( $test['error'] ?? '' ) ) . '</code>'
					);
				}
				break;
		}

		if ( '' === $body ) {
			return '';
		}
		return '<div class="' . esc_attr( $class ) . '"><p>' . $body . '</p></div>';
	}

	private static function get_profile_handle( WP_User $user ): string {
		$handle = get_user_meta( $user->ID, self::PROFILE_HANDLE_META, true );

		if ( '' === $handle ) {
			$handle = $user->user_nicename ?: $user->user_login;
		}

		return self::sanitize_profile_handle( $handle );
	}

	private static function sanitize_profile_handle( string $handle ): string {
		$handle = strtolower( trim( $handle ) );
		$handle = preg_replace( '/^@+/', '', $handle );
		$handle = preg_replace( '/[^a-z0-9._-]/', '', $handle );
		$handle = trim( $handle, '._-' );

		return substr( $handle, 0, 30 );
	}

	private static function save_image_meta( int $user_id, string $meta_key, string $post_key ): void {
		if ( ! array_key_exists( $post_key, $_POST ) ) {
			return;
		}

		$attachment_id = absint( wp_unslash( $_POST[ $post_key ] ) );

		if ( ! $attachment_id ) {
			delete_user_meta( $user_id, $meta_key );
			return;
		}

		if ( wp_attachment_is_image( $attachment_id ) ) {
			update_user_meta( $user_id, $meta_key, $attachment_id );
		}
	}
}

Radical_Socials_Settings_Page::init();
