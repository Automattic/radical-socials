<?php
/**
 * Frontend Editor
 *
 * Registers the `radical-socials/frontend-editor` block and the
 * `radical-socials-frontend` script/style handles. block.json wires
 * those handles as `viewScript`/`viewStyle`, so WordPress auto-enqueues
 * them whenever the block renders. We additionally enqueue the same
 * handles on every front-end page for users with publish capability,
 * so the Custom Bar "Create" overlay can be opened from anywhere — not
 * just pages that happen to contain the block. The dual wiring relies
 * on WP's normal handle deduplication; if both paths fire, the script
 * is still only sent to the browser once.
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

		// Always-on enqueue for the Create button + modal. The Custom
		// Bar's Create item is itself gated on `publish_posts`, so a
		// reader/visitor never sees the trigger and the script payload
		// only lands for accounts that can actually use it.
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_for_publishers' ] );

		// Auto-inject the composer block onto the home / front page when
		// no theme template already places it. Runs at render time so
		// theme overrides (placing the block themselves) take precedence
		// automatically.
		add_filter( 'render_block', [ self::class, 'maybe_inject_into_main' ], 10, 2 );
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
			'radical-socials-tools',
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

	/**
	 * Enqueue the frontend script + style on every front-end page for
	 * publish-capable users so the Create overlay can be triggered from
	 * the Custom Bar regardless of which template is rendering. When the
	 * editor block IS present on the same page, the block's viewScript
	 * declaration also asks for the same handles — wp_enqueue_script's
	 * dedup-by-handle handles that cleanly.
	 */
	public static function enqueue_for_publishers(): void {
		if ( ! self::current_user_can_publish() ) {
			return;
		}
		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			// register_assets() bails when the build asset file is
			// missing (fresh checkout pre-`npm run build`). Mirror that
			// — nothing to enqueue means the trigger script wouldn't
			// run anyway.
			return;
		}
		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * On the home / front-page request, inject our composer as the
	 * first child of the page's `<main>` element when no relevant theme
	 * template already places it. Per-request cached so the
	 * candidate-template scan runs at most once.
	 *
	 * Targets the `core/group` block with `tagName: main`. Themes that
	 * write a bare `<main>` HTML tag instead of a Group block won't be
	 * matched — that's the "if there is no main tag, we don't append it"
	 * branch of the rule, applied conservatively to avoid mangling
	 * HTML we don't fully understand.
	 *
	 * @param string $block_content  Rendered HTML of one block.
	 * @param array  $block          Parsed block (name + attrs + …).
	 */
	public static function maybe_inject_into_main( string $block_content, array $block ): string {
		// Cheapest checks first: bail before WP's query state lookups
		// on the >99% of blocks we don't care about.
		if ( 'core/group' !== ( $block['blockName'] ?? '' ) ) {
			return $block_content;
		}
		if ( 'main' !== ( $block['attrs']['tagName'] ?? '' ) ) {
			return $block_content;
		}
		if ( ! is_front_page() && ! is_home() ) {
			return $block_content;
		}
		if ( ! self::should_auto_inject() ) {
			return $block_content;
		}

		// Render the editor block ourselves so the result is plain HTML
		// ready to splice into the parent's rendered output. do_blocks
		// also pulls the block's viewScript/viewStyle through the
		// enqueue pipeline as a side-effect — same handles our
		// enqueue_for_publishers() already wired in, deduped at
		// enqueue time.
		$editor_html = do_blocks( '<!-- wp:radical-socials/frontend-editor /-->' );
		if ( '' === trim( $editor_html ) ) {
			return $block_content;
		}

		// Insert right after the opening <main …> tag. preg_replace
		// returns null on regex error — keep the original output in
		// that pathological case rather than blowing up the page.
		$updated = preg_replace(
			'~(<main\b[^>]*>)~i',
			'$1' . $editor_html,
			$block_content,
			1
		);
		return is_string( $updated ) ? $updated : $block_content;
	}

	/**
	 * Decide once per request whether to inject the composer. The
	 * answer is "yes" only when none of the four candidate templates
	 * for the home-page request already includes the composer block —
	 * matching the spec: front-page → blog → home → index, in that
	 * priority order. If any of them place the block themselves, we
	 * step out of the way entirely.
	 *
	 * The lookup goes through `get_block_templates` so user-customized
	 * templates stored in the database (wp_template post overrides
	 * created by the Site Editor) are honored alongside theme files.
	 */
	private static function should_auto_inject(): bool {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		// Restrict the slug scan to the templates that would actually
		// render for the home URL given the current reading settings.
		// `blog` isn't part of WP's standard hierarchy, but some themes
		// use it as a custom slug, so honor it as the user requested.
		$show_on_front = (string) get_option( 'show_on_front', 'posts' );
		$candidates    = 'page' === $show_on_front
			? [ 'front-page', 'index' ]
			: [ 'front-page', 'home', 'blog', 'index' ];

		if ( ! function_exists( 'get_block_templates' ) ) {
			$cached = false;
			return $cached;
		}

		$templates = get_block_templates(
			[ 'slug__in' => $candidates ],
			'wp_template'
		);

		foreach ( $templates as $tpl ) {
			if ( isset( $tpl->content ) && false !== strpos( (string) $tpl->content, 'wp:radical-socials/frontend-editor' ) ) {
				$cached = false;
				return $cached;
			}
		}

		$cached = true;
		return $cached;
	}
}

Radical_Socials_Frontend_Editor::init();
