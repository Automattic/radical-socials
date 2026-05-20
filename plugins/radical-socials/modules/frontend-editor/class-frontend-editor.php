<?php
/**
 * Frontend Editor
 *
 * Registers the `radical-socials/frontend-editor` block and the
 * `radical-socials-frontend` script/style handles. The block.json
 * references those handles as `viewScript`/`viewStyle`, so WordPress
 * auto-enqueues them only when the block is actually rendered on the
 * front end. That matters: the style depends on `wp-edit-blocks` and
 * `wp-format-library`, both of which carry broad block-editor
 * selectors — global enqueue would bleed editor chrome onto every
 * front-end page.
 *
 * The handles are only registered when the current user can publish
 * posts. For everyone else the view-* references in block.json resolve
 * to nothing, WP silently skips them, and no heavy CSS lands.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Frontend_Editor {

	const SCRIPT_HANDLE = 'radical-socials-frontend';
	const STYLE_HANDLE  = 'radical-socials-frontend';

	public static function init(): void {
		// Both registration and block setup live on `init`. Asset
		// registration runs at priority 9 so it happens BEFORE the
		// `register_block_type()` call at priority 10 reads block.json
		// and tries to resolve viewScript/viewStyle handles.
		add_action( 'init', [ self::class, 'register_assets' ], 9 );
		add_action( 'init', [ self::class, 'register_block' ] );
	}

	private static function current_user_can_publish(): bool {
		return is_user_logged_in() && current_user_can( 'publish_posts' );
	}

	public static function register_assets(): void {
		// No publish cap → don't register. The viewScript/viewStyle
		// handles in block.json then resolve to nothing on render and
		// WP skips the enqueue entirely — keeping logged-out / reader
		// page weights down.
		if ( ! self::current_user_can_publish() ) {
			return;
		}

		$asset_file = plugin_dir_path( __FILE__ ) . '../../build/frontend.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_register_script(
			self::SCRIPT_HANDLE,
			plugin_dir_url( __FILE__ ) . '../../build/frontend.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_register_style(
			self::STYLE_HANDLE,
			plugin_dir_url( __FILE__ ) . '../../build/frontend.css',
			// Three deps only — verified minimum. We intentionally do
			// NOT pull in `wp-edit-blocks` (2.8k lines of admin block
			// chrome with hundreds of font-size declarations on broad
			// selectors) or `wp-format-library` (`:root` custom-prop
			// declarations that redefine theme typography vars). They
			// were suggested by a docs review but a runtime check
			// showed neither is needed for our composer — the
			// MediaPlaceholder, DropZone, and RichText toolbar all
			// render correctly with just wp-block-editor + wp-components.
			// Adding them bleeds editor typography into the surrounding
			// frontend page (which on the home page is the entire feed).
			[ 'wp-components', 'wp-block-editor', 'wp-block-library' ],
			$asset['version']
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'radicalSocials',
			[
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'restUrl' => rest_url(),
			]
		);

		// Wire JS strings (composer placeholder, error messages, etc.) to
		// the same /languages/ folder PHP uses. Without this call wp.org's
		// translate.wordpress.org generates .json catalogs that never load.
		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'radical-socials',
			plugin_dir_path( __FILE__ ) . '../../languages'
		);
	}

	public static function register_block(): void {
		register_block_type(
			__DIR__ . '/block.json',
			[ 'render_callback' => [ self::class, 'render' ] ]
		);
	}

	public static function render(): string {
		if ( ! self::current_user_can_publish() ) {
			return '';
		}
		return '<div id="radical-socials-editor"></div>';
	}
}

Radical_Socials_Frontend_Editor::init();
