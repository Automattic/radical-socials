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

	public static function enqueue( string $hook ): void {
		if ( 'toplevel_page_radical-socials-settings' !== $hook ) {
			return;
		}
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

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$updated = false;
		$user    = wp_get_current_user();

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

			$updated = true;
			$user    = get_user_by( 'id', $user->ID ) ?: wp_get_current_user();
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

		$btn_upload = 'upload-button button-hero button';
		$btn_change = 'button';

		$classes_for_button    = $has_logo ? $btn_change  : $btn_upload;
		$classes_for_alt       = $has_logo ? $btn_upload  : $btn_change;
		$classes_for_preview   = 'site-icon-preview settings' . ( $has_logo ? ' has-site-icon' : ' hidden' );
		?>
		<div class="wrap rs-settings-wrap">
			<h1><?php esc_html_e( 'Profile Settings', 'radical-socials' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'radical-socials' ); ?></p>
				</div>
			<?php endif; ?>

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
