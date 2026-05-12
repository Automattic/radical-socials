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
	}

	private static function active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'profile';
		return in_array( $tab, [ 'profile', 'following' ], true ) ? $tab : 'profile';
	}

	public static function enqueue( string $hook ): void {
		if ( 'toplevel_page_radical-socials-settings' !== $hook ) {
			return;
		}

		if ( 'profile' === self::active_tab() ) {
			wp_enqueue_media();
			wp_enqueue_script( 'site-icon' );
			wp_enqueue_style( 'site-icon' );

			wp_enqueue_style(
				'rs-settings-page',
				plugin_dir_url( __FILE__ ) . 'assets/settings.css',
				[],
				filemtime( __DIR__ . '/assets/settings.css' ) ?: '1'
			);

			wp_enqueue_script(
				'rs-settings-page',
				plugin_dir_url( __FILE__ ) . 'assets/settings.js',
				[ 'media-editor' ],
				filemtime( __DIR__ . '/assets/settings.js' ) ?: '1',
				true
			);
		}

		if ( 'following' === self::active_tab() ) {
			wp_enqueue_script(
				'rs-following-settings',
				plugin_dir_url( __FILE__ ) . 'assets/following.js',
				[],
				filemtime( __DIR__ . '/assets/following.js' ) ?: '1',
				true
			);
			$nonce = wp_create_nonce( 'wp_rest' );
			wp_localize_script( 'rs-following-settings', 'rsFollowing', [
				'apiUrl'              => rest_url( 'radical-socials/v1/following' ),
				'importFromAccountUrl' => rest_url( 'radical-socials/v1/following/import-from-account' ),
				'opmlParseUrl'        => rest_url( 'radical-socials/v1/following/opml/parse' ),
				'opmlEntryUrl'  => rest_url( 'radical-socials/v1/following/opml/entry' ),
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
					'colType'       => __( 'Type', 'radical-socials' ),
					'colCategories' => __( 'Categories', 'radical-socials' ),
					'starLabel'     => __( 'Star this feed', 'radical-socials' ),
					'unstarLabel'   => __( 'Unstar this feed', 'radical-socials' ),
					'addSummary'    => __( 'Done — %added% added, %skipped% already existed, %failed% failed.', 'radical-socials' ),
					'failuresLabel' => __( 'The following could not be added:', 'radical-socials' ),
					'errorNetwork'  => __( 'Network error', 'radical-socials' ),
					'errorUnknown'  => __( 'Unknown error', 'radical-socials' ),
					'errors'        => [
						'invalid_url'                   => __( 'Invalid URL — make sure it starts with https://', 'radical-socials' ),
						'unsafe_url'                    => __( 'URL must be a public HTTP(S) address', 'radical-socials' ),
						'activitypub_unavailable'       => __( 'ActivityPub plugin is not active', 'radical-socials' ),
						'activitypub_user_not_found'    => __( 'Account not found — check the handle or URL', 'radical-socials' ),
						'activitypub_already_following' => __( 'Already following', 'radical-socials' ),
						'already_exists'                => __( 'Already following', 'radical-socials' ),
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
		$updated = false;
		$user    = wp_get_current_user();

		// ── Handle actions before any output ────────────────────────────────

		// WP.com disconnect (GET, nonce-protected).
		if (
			isset( $_GET['rs_action'] ) &&
			'wpcom_disconnect' === $_GET['rs_action'] &&
			isset( $_GET['_wpnonce'] ) &&
			wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'rs_wpcom_disconnect' )
		) {
			Radical_Socials_WPCOM_OAuth::disconnect();
			wp_safe_redirect( admin_url( 'admin.php?page=radical-socials-settings&tab=following&rs_wpcom=disconnected' ) );
			exit;
		}

		// Profile settings save.
		$updated = false;
		if (
			isset( $_POST['rs_settings_nonce'] ) &&
			wp_verify_nonce( wp_unslash( $_POST['rs_settings_nonce'] ), 'rs_settings_save' )
		) {
			$user_update = [
				'ID' => $user->ID,
			];

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

		$logo_id     = (int) get_theme_mod( 'custom_logo' );
		$logo_url    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$has_logo    = (bool) $logo_url;
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
			<h1><?php esc_html_e( 'Radical Socials', 'radical-socials' ); ?></h1>

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

			<?php if ( isset( $_GET['rs_oauth_error'] ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Could not connect WP.com account. Please try again.', 'radical-socials' ); ?></p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'radical-socials' ); ?>">
				<a href="<?php echo esc_url( $tab_url( 'profile' ) ); ?>"
				   class="nav-tab <?php echo 'profile' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Profile', 'radical-socials' ); ?>
				</a>
				<a href="<?php echo esc_url( $tab_url( 'following' ) ); ?>"
				   class="nav-tab <?php echo 'following' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Following', 'radical-socials' ); ?>
				</a>
			</nav>

			<?php if ( 'profile' === $active_tab ) : ?>

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
			</style>

			<div class="rs-following-layout">

				<!-- Left column: controls -->
				<div>
					<?php
					$following_page = get_page_by_path( 'following', OBJECT, 'page' );
					$following_url  = $following_page ? get_permalink( $following_page->ID ) : home_url( '/following/' );
					?>
					<p class="description"><?php printf(
						/* translators: %s: link to the /following page */
						esc_html__( 'Configure what appears at %s. Each source type stacks on top of the last — connect more to see more.', 'radical-socials' ),
						'<a href="' . esc_url( $following_url ) . '" target="_blank" rel="noopener">' . esc_html( $following_url ) . '</a>'
					); ?></p>

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

					<div style="margin-top:16px">
						<label for="rs-add-input"><strong><?php esc_html_e( 'Add feeds or accounts', 'radical-socials' ); ?></strong></label>
						<p class="description" style="margin-bottom:8px">
							<?php esc_html_e( 'One per line. RSS/Atom URLs or ActivityPub handles (e.g. @someone@mastodon.social).', 'radical-socials' ); ?><br>
							<?php esc_html_e( 'ActivityPub supports: Mastodon, Pixelfed, Misskey, Pleroma, Peertube, Lemmy, Friendica, Hubzilla, and any ActivityPub-compatible account.', 'radical-socials' ); ?>
						</p>
						<textarea id="rs-add-input" rows="5" class="large-text" placeholder="https://example.com/feed&#10;@someone@mastodon.social"></textarea>
						<p>
							<button id="rs-add-btn" type="button" class="button button-primary"><?php esc_html_e( 'Add', 'radical-socials' ); ?></button>
						</p>
						<div id="rs-add-progress" hidden style="margin-top:8px">
							<progress id="rs-add-progress-bar" value="0" max="100" style="width:100%;max-width:100%;display:block"></progress>
							<span id="rs-add-progress-text"></span>
						</div>
						<div id="rs-add-failures" hidden style="margin-top:8px"></div>
					</div>

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

			<?php endif; ?>
		</div>
		<?php
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
