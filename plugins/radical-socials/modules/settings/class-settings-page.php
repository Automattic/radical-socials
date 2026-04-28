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

	public static function enqueue( string $hook ): void {
		if ( 'toplevel_page_radical-socials-settings' !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'site-icon' );
		wp_enqueue_style( 'site-icon' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

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

			$updated = true;
		}

		$logo_id  = (int) get_theme_mod( 'custom_logo' );
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$has_logo = (bool) $logo_url;

		$btn_upload = 'upload-button button-hero button';
		$btn_change = 'button';

		$classes_for_button    = $has_logo ? $btn_change  : $btn_upload;
		$classes_for_alt       = $has_logo ? $btn_upload  : $btn_change;
		$classes_for_preview   = 'site-icon-preview settings' . ( $has_logo ? ' has-site-icon' : ' hidden' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Radical Socials — Settings', 'radical-socials' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'radical-socials' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post">
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
		</div>
		<?php
	}
}

Radical_Socials_Settings_Page::init();
