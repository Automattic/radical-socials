<?php
/**
 * Custom Bar
 *
 * Hides the WP admin bar on the frontend and renders a lightweight replacement:
 * a collapsible vertical rail on desktop, a fixed bottom strip on mobile.
 *
 * Only active for logged-in users. Enable/disable the module by adding or
 * removing the require_once in radical-socials.php.
 *
 * Extenders can add items to the bar using the standard admin_bar_menu hook,
 * exactly as they would for the default WP admin bar.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Custom_Bar {

	/** Collapsed rail width (px). Kept in sync with --rs-w in the CSS. */
	const W = 48;

	/** Mobile bar height (px). Kept in sync with --rs-h in the CSS. */
	const H = 48;

	/** Desktop breakpoint (px). Kept in sync with --rs-bp-* in the CSS. */
	const BREAKPOINT = 782;

	public static function init(): void {
		if ( is_admin() ) {
			return;
		}

		// Hide WP's own admin bar — we render our own.
		add_filter( 'show_admin_bar', '__return_false' );

		// Everything below only matters for logged-in users.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue'        ] );
		add_action( 'wp_head',            [ __CLASS__, 'offset_styles'  ], 1 );
		add_action( 'wp_footer',          [ __CLASS__, 'render'         ] );
		add_filter( 'body_class',         [ __CLASS__, 'add_body_class' ] );
	}

	/**
	 * Enqueue the stylesheet (and dashicons, which WP doesn't load on the
	 * frontend by default).
	 */
	public static function enqueue(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		wp_enqueue_style(
			'rs-custom-bar',
			plugin_dir_url( __FILE__ ) . 'assets/custom-bar.css',
			[ 'dashicons' ],
			filemtime( __DIR__ . '/assets/custom-bar.css' ) ?: '1'
		);
	}

	/**
	 * Output a small inline style block that displaces page content so the
	 * bar never overlaps it when collapsed.
	 *
	 * Desktop → push content right by the collapsed rail width.
	 * Mobile  → push content up by the bar height.
	 */
	public static function offset_styles(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$w  = self::W;
		$h  = self::H;
		$bp = self::BREAKPOINT;
		?>
		<style id="rs-bar-offset">
		@media (min-width: <?php echo $bp + 1; ?>px) {
			html { margin-left: <?php echo $w; ?>px; }
		}
		@media (max-width: <?php echo $bp; ?>px) {
			body { padding-bottom: <?php echo $h; ?>px; }
		}
		</style>
		<?php
	}

	/**
	 * Render the bar HTML in the footer.
	 */
	public static function render(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user    = wp_get_current_user();
		$avatar  = get_avatar( $user->ID, self::H, '', esc_attr__( 'Profile', 'radical-socials' ), [ 'class' => 'rs-bar-avatar' ] );
		$pending = (int) wp_count_comments()->moderated;
		?>
		<nav id="rs-bar" aria-label="<?php esc_attr_e( 'Site navigation', 'radical-socials' ); ?>">
			<ul>
				<li>
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="rs-bar-link" aria-label="<?php esc_attr_e( 'Home', 'radical-socials' ); ?>">
						<span class="dashicons dashicons-admin-home" aria-hidden="true"></span>
						<span class="rs-bar-label"><?php esc_html_e( 'Home', 'radical-socials' ); ?></span>
					</a>
				</li>
				<li>
					<a href="#" class="rs-bar-link" aria-label="<?php esc_attr_e( 'Create', 'radical-socials' ); ?>">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<span class="rs-bar-label"><?php esc_html_e( 'Create', 'radical-socials' ); ?></span>
					</a>
				</li>
				<li>
					<a href="#" class="rs-bar-link" aria-label="<?php esc_attr_e( 'Explore', 'radical-socials' ); ?>">
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<span class="rs-bar-label"><?php esc_html_e( 'Explore', 'radical-socials' ); ?></span>
					</a>
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ); ?>" class="rs-bar-link" aria-label="<?php echo $pending > 0 ? esc_attr( sprintf( __( 'Comments — %d pending', 'radical-socials' ), $pending ) ) : esc_attr__( 'Comments', 'radical-socials' ); ?>">
						<span class="rs-bar-icon-wrap">
							<span class="dashicons dashicons-admin-comments" aria-hidden="true"></span>
							<?php if ( $pending > 0 ) : ?>
								<span class="rs-bar-badge" aria-hidden="true"><?php echo $pending > 99 ? '99+' : $pending; ?></span>
							<?php endif; ?>
						</span>
						<span class="rs-bar-label"><?php esc_html_e( 'Comments', 'radical-socials' ); ?></span>
					</a>
				</li>
				<li>
					<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>" class="rs-bar-link" aria-label="<?php esc_attr_e( 'Profile', 'radical-socials' ); ?>">
						<?php echo $avatar; ?>
						<span class="rs-bar-label"><?php esc_html_e( 'Profile', 'radical-socials' ); ?></span>
					</a>
				</li>
			</ul>
		</nav>
		<?php
	}

	/**
	 * Add a body class so theme CSS can react to the bar being present.
	 *
	 * @param string[] $classes
	 * @return string[]
	 */
	public static function add_body_class( array $classes ): array {
		if ( is_user_logged_in() ) {
			$classes[] = 'has-rs-bar';
		}

		return $classes;
	}
}

Radical_Socials_Custom_Bar::init();
