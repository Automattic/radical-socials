<?php
/**
 * Settings Page
 *
 * Adds a Settings submenu under the Radical Socials top-level menu.
 * Provides admin-side forms for site title (blogname option) and site logo
 * (custom_logo theme mod — same storage used by the Customizer and core blocks).
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Settings_Page {

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
				'apiUrl'        => rest_url( 'radical-socials/v1/following' ),
				'opmlParseUrl'  => rest_url( 'radical-socials/v1/following/opml/parse' ),
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
			if ( isset( $_POST['blogname'] ) ) {
				update_option( 'blogname', sanitize_text_field( wp_unslash( $_POST['blogname'] ) ) );
			}

			// custom_logo is always present in the POST (hidden input).
			// site-icon.js sets the value to 'false' (string) on remove.
			if ( array_key_exists( 'custom_logo', $_POST ) ) {
				$logo_id = absint( $_POST['custom_logo'] );
				if ( $logo_id ) {
					set_theme_mod( 'custom_logo', $logo_id );
				} else {
					remove_theme_mod( 'custom_logo' );
				}
			}

			wp_safe_redirect( admin_url( 'admin.php?page=radical-socials-settings&tab=profile&rs_settings=updated' ) );
			exit;
		}

		// ── Tab URLs ─────────────────────────────────────────────────────────

		$tab_url = fn( string $tab ) => admin_url( 'admin.php?page=radical-socials-settings&tab=' . $tab );
		?>
		<div class="wrap">
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

			<?php
			$logo_id  = (int) get_theme_mod( 'custom_logo' );
			$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
			$has_logo = (bool) $logo_url;

			$btn_upload          = 'upload-button button-hero button';
			$btn_change          = 'button';
			$classes_for_button  = $has_logo ? $btn_change  : $btn_upload;
			$classes_for_alt     = $has_logo ? $btn_upload  : $btn_change;
			$classes_for_preview = 'site-icon-preview settings' . ( $has_logo ? ' has-site-icon' : ' hidden' );
			?>

			<form method="post" style="margin-top:20px">
				<?php wp_nonce_field( 'rs_settings_save', 'rs_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="blogname"><?php esc_html_e( 'Site Title', 'radical-socials' ); ?></label>
						</th>
						<td>
							<input
								name="blogname"
								type="text"
								id="blogname"
								value="<?php echo esc_attr( get_option( 'blogname' ) ); ?>"
								class="regular-text"
							/>
						</td>
					</tr>

					<?php if ( current_user_can( 'upload_files' ) ) : ?>
					<tr class="hide-if-no-js site-icon-section">
						<th scope="row"><?php esc_html_e( 'Site Logo', 'radical-socials' ); ?></th>
						<td>
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
						</td>
					</tr>
					<?php endif; ?>
				</table>

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
}

Radical_Socials_Settings_Page::init();
