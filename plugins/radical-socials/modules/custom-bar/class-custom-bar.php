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
	const BREAKPOINT = 1200;

	public static function init(): void {
		// Block registration must run in admin too so the editor can list the block.
		add_action( 'init',                       [ __CLASS__, 'register_blocks' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'localize_block_editor' ] );

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

	public static function register_blocks(): void {
		register_block_type( __DIR__ . '/blocks/social-menu' );
	}

	/**
	 * Expose the link list to the social-menu block's editor script so it can
	 * render the list in React (instead of via ServerSideRender). This lets
	 * useBlockProps attach directly to the real <ul>, so the layout system's
	 * orientation/blockGap controls work in the editor without static CSS.
	 */
	public static function localize_block_editor(): void {
		$handle = generate_block_asset_handle( 'radical-socials/social-menu', 'editorScript' );
		wp_add_inline_script(
			$handle,
			'window.radicalSocialsSocialMenu = ' . wp_json_encode( [
				'links' => self::get_links(),
			] ) . ';',
			'before'
		);
	}

	/**
	 * Single source of truth for the navigation links shared by the custom
	 * bar and the radical-socials/nav-links block.
	 *
	 * Each item:
	 *   id    (string) — used as a CSS class and for bar-specific tweaks
	 *   label (string) — visible link text
	 *   url   (string) — link href
	 *   icon  (string) — dashicons class name (used by the bar; ignored by the block)
	 *   attrs (array)  — optional extra HTML attributes for the <a> element
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_links(): array {
		$links = [
			[
				'id'    => 'home',
				'label' => __( 'Home', 'radical-socials' ),
				'url'   => home_url( '/' ),
				'icon'  => 'dashicons-admin-home',
			],
		];

		if ( is_user_logged_in() && current_user_can( 'publish_posts' ) ) {
			$links[] = [
				'id'    => 'create',
				'label' => __( 'Create', 'radical-socials' ),
				'url'   => '#',
				'icon'  => 'dashicons-plus-alt2',
				'attrs' => [ 'data-rs-action' => 'open-editor' ],
			];
		}

		if ( current_user_can( 'moderate_comments' ) ) {
			$links[] = [
				'id'    => 'comments',
				'label' => __( 'Comments', 'radical-socials' ),
				'url'   => admin_url( 'edit-comments.php?comment_status=moderated' ),
				'icon'  => 'dashicons-admin-comments',
			];
		}

		if ( current_user_can( 'manage_options' ) ) {
			$links[] = [
				'id'    => 'profile',
				'label' => __( 'Profile', 'radical-socials' ),
				'url'   => admin_url( 'admin.php?page=radical-socials-settings' ),
				'icon'  => 'dashicons-admin-users',
			];
		}

		return apply_filters( 'radical_socials_nav_links', $links );
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

		$user      = wp_get_current_user();
		$avatar_id = (int) get_user_meta( $user->ID, 'rs_profile_avatar_id', true );
		$avatar    = $avatar_id ? wp_get_attachment_image(
			$avatar_id,
			[ self::H, self::H ],
			false,
			[
				'class' => 'rs-bar-avatar',
				'alt'   => esc_attr__( 'Profile', 'radical-socials' ),
			]
		) : '';
		$avatar    = $avatar ?: get_avatar( $user->ID, self::H, '', esc_attr__( 'Profile', 'radical-socials' ), [ 'class' => 'rs-bar-avatar' ] );
		$pending   = (int) wp_count_comments()->moderated;
		$links     = self::get_links();
		?>
		<nav id="rs-bar" aria-label="<?php esc_attr_e( 'Site navigation', 'radical-socials' ); ?>">
			<ul>
				<?php foreach ( $links as $link ) : ?>
					<?php
					$extra_attrs = '';
					foreach ( $link['attrs'] ?? [] as $name => $value ) {
						$extra_attrs .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
					}
					$aria = 'comments' === $link['id'] && $pending > 0
						? sprintf( __( 'Comments — %d pending', 'radical-socials' ), $pending )
						: $link['label'];
					?>
					<li>
						<a href="<?php echo esc_url( $link['url'] ); ?>" class="rs-bar-link" aria-label="<?php echo esc_attr( $aria ); ?>"<?php echo $extra_attrs; ?>>
							<?php echo self::render_bar_icon( $link, $avatar, $pending ); ?>
							<span class="rs-bar-label"><?php echo esc_html( $link['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
	}

	/**
	 * Render the icon (or avatar/badge) for a given bar link.
	 * Keeps the bar-specific decorations (avatar swap for profile, badge for
	 * comments) out of the shared link data.
	 */
	private static function render_bar_icon( array $link, string $avatar, int $pending ): string {
		if ( 'profile' === $link['id'] ) {
			return $avatar;
		}
		if ( 'comments' === $link['id'] && $pending > 0 ) {
			return sprintf(
				'<span class="rs-bar-icon-wrap"><span class="dashicons %s" aria-hidden="true"></span><span class="rs-bar-badge" aria-hidden="true">%s</span></span>',
				esc_attr( $link['icon'] ),
				$pending > 99 ? '99+' : (int) $pending
			);
		}
		return sprintf(
			'<span class="dashicons %s" aria-hidden="true"></span>',
			esc_attr( $link['icon'] )
		);
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
