<?php
/**
 * Cover Photo — Block Bindings source.
 *
 * Exposes the site owner's cover photo (the `rs_profile_banner_id` user
 * meta managed under Settings → Profile) as a Block Bindings source so
 * any `core/cover` block in a template can render it dynamically and
 * still keep all of core's built-in tools (focal point, overlay color
 * and dim ratio, repeat / parallax, height, content-overlay blocks).
 *
 * Usage in a theme template (or via the editor's "Connect to source"
 * UI in the Block Inspector):
 *
 *   <!-- wp:cover {
 *           "dimRatio": 30,
 *           "metadata": {
 *               "bindings": {
 *                   "url": { "source": "radical-socials/cover-photo" }
 *               }
 *           }
 *       } -->
 *   <div class="wp-block-cover">…</div>
 *   <!-- /wp:cover -->
 *
 * The block's saved `url` attribute is overridden at render time by the
 * value returned from this source. If no cover is set, the binding
 * returns null and the cover block falls back to its saved `url` (or
 * renders an empty placeholder).
 *
 * Whose cover is rendered:
 *   - Default: the first administrator user (matches the "Blog Mode"
 *     semantic — one site owner identity).
 *   - Filterable via `radical_socials_cover_photo_user_id` so a theme
 *     could swap to e.g. the queried author on `is_author()` pages.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

class Radical_Socials_Cover_Photo_Binding {

	const SOURCE_NAME = 'radical-socials/cover-photo';
	const USER_META   = 'rs_profile_banner_id';

	public static function init(): void {
		add_action( 'init', [ self::class, 'register_source' ] );
		// Core's bindings system uses a hard-coded whitelist of which block
		// attributes can be bound (see get_block_bindings_supported_attributes).
		// `core/cover` isn't on it despite having `url` marked as
		// `role: content` in its block.json. Opt it in via the per-block
		// filter so the source callback fires for cover blocks.
		add_filter( 'block_bindings_supported_attributes_core/cover', [ self::class, 'allow_url_binding_on_cover' ] );
		// Even with the filter above, the bindings runtime can't rewrite
		// cover's rendered <img> tag because cover's `url` attribute has
		// no `source` mapping in its block.json — WP_Block::replace_html
		// silently returns on that branch. We do the rewrite ourselves so
		// the resolved URL actually lands on the page. Drop this hook if
		// core ever adds the source mapping for cover.
		add_filter( 'render_block', [ self::class, 'rewrite_cover_image' ], 10, 2 );
	}

	public static function register_source(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}
		register_block_bindings_source(
			self::SOURCE_NAME,
			[
				'label'              => __( 'Cover photo', 'radical-socials-tools' ),
				'get_value_callback' => [ self::class, 'get_value' ],
			]
		);
	}

	/**
	 * Add `url` to the list of attributes core/cover allows to be bound.
	 * Without this filter, the bindings registry never invokes our source
	 * callback for cover blocks even when their markup declares a binding.
	 *
	 * @param string[] $supported
	 * @return string[]
	 */
	public static function allow_url_binding_on_cover( $supported ) {
		$supported   = is_array( $supported ) ? $supported : [];
		$supported[] = 'url';
		return array_values( array_unique( $supported ) );
	}

	/**
	 * Resolve the bound attribute's value at render time. Called once per
	 * bound attribute per block instance.
	 *
	 * @param array       $source_args     Args passed in the block's bindings declaration (unused).
	 * @param WP_Block    $block_instance  The block being rendered (unused).
	 * @param string      $attribute_name  Which attribute we're resolving for — only 'url' is bindable on core/cover.
	 * @return string|null Image URL on success, null to fall through to the block's saved value.
	 */
	public static function get_value( array $source_args, $block_instance, string $attribute_name ) {
		unset( $source_args, $block_instance );

		if ( 'url' !== $attribute_name ) {
			return null;
		}

		$user_id = self::resolve_user_id();
		if ( ! $user_id ) {
			return null;
		}

		$banner_id = (int) get_user_meta( $user_id, self::USER_META, true );
		if ( ! $banner_id ) {
			return null;
		}

		$url = wp_get_attachment_image_url( $banner_id, 'large' );
		return $url ?: null;
	}

	/**
	 * Pick which user's cover photo to render. Filterable so themes can
	 * override per-context (e.g. the queried author on `is_author()`).
	 *
	 * Default: first administrator. Cheap to compute — WP caches the
	 * user query and bindings only fire when a cover block is on the
	 * current request's template.
	 */
	private static function resolve_user_id(): int {
		$default = 0;
		$admins  = get_users( [
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		] );
		if ( $admins ) {
			$default = (int) $admins[0];
		}

		/**
		 * Filters which user's cover photo the `radical-socials/cover-photo`
		 * binding source renders. Return 0 to render nothing.
		 *
		 * @param int $user_id The default user id (first administrator).
		 */
		return (int) apply_filters( 'radical_socials_cover_photo_user_id', $default );
	}

	/**
	 * When a core/cover block declares our binding source on its `url`
	 * attribute, rewrite the rendered `<img class="wp-block-cover__image-background">`
	 * src to the resolved cover photo URL. This is the part WP's bindings
	 * runtime can't currently do for cover (no `source` mapping on the
	 * attribute), so we do it ourselves at render time.
	 *
	 * @param string $block_content Rendered block HTML.
	 * @param array  $block         Parsed block (name + attrs + …).
	 * @return string
	 */
	public static function rewrite_cover_image( string $block_content, array $block ): string {
		if ( 'core/cover' !== ( $block['blockName'] ?? '' ) ) {
			return $block_content;
		}
		$source = $block['attrs']['metadata']['bindings']['url']['source'] ?? '';
		if ( self::SOURCE_NAME !== $source ) {
			return $block_content;
		}

		$url = self::get_value( [], null, 'url' );
		if ( ! $url ) {
			return $block_content;
		}

		// Path 1: an <img class="wp-block-cover__image-background"> exists in the
		// saved markup (placeholder image baked in). Rewrite its src.
		$p     = new WP_HTML_Tag_Processor( $block_content );
		$found = false;
		while ( $p->next_tag( [ 'tag_name' => 'img', 'class_name' => 'wp-block-cover__image-background' ] ) ) {
			$p->set_attribute( 'src', $url );
			$found = true;
		}
		if ( $found ) {
			return $p->get_updated_html();
		}

		// Path 2: no placeholder img — author wrote a cover with just the
		// overlay color (e.g. contrast-4) as a fallback. Inject the img
		// right after the background span so the user's cover photo shows
		// through whatever dim ratio the author chose.
		$img = sprintf(
			'<img class="wp-block-cover__image-background" alt="" src="%s" data-object-fit="cover" />',
			esc_url( $url )
		);
		$updated = preg_replace(
			'~(<span\s[^>]*class="[^"]*\bwp-block-cover__background\b[^"]*"[^>]*>\s*</span>)~i',
			'$1' . $img,
			$block_content,
			1,
			$count
		);
		return ( null !== $updated && $count > 0 ) ? $updated : $block_content;
	}
}

Radical_Socials_Cover_Photo_Binding::init();
